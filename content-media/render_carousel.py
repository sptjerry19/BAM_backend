"""Render carousel slides to PNG with headless Chromium (Playwright).

Called by App\\Services\\ContentStudio\\Render\\PlaywrightCarouselRenderer with an argv array:

    python3 render_carousel.py --input input.json --out /tmp/dir

Security model:
  * the page template is a fixed file; slide text is inserted with textContent only
  * the browser has no network: every request except data: URLs is aborted
  * fonts are embedded as data: URLs

Exit status: 0 = every slide written, 2 = a slide's text does not fit, 1 = anything else.
"""
import argparse
import base64
import json
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()

    data = json.loads(Path(args.input).read_text(encoding="utf-8"))
    html = Path(data["template"]).read_text(encoding="utf-8")
    fonts = [
        [base64.b64encode(Path(path).read_bytes()).decode("ascii"), weight]
        for path, weight in ((data["fonts"]["regular"], 400), (data["fonts"]["bold"], 700))
    ]
    width, height = int(data["width"]), int(data["height"])
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(args=["--no-sandbox", "--disable-dev-shm-usage"])
        try:
            context = browser.new_context(viewport={"width": width, "height": height}, device_scale_factor=1)
            context.route("**/*", lambda route: route.continue_() if route.request.url.startswith("data:") else route.abort())
            page = context.new_page()

            for slide in data["slides"]:
                page.set_content(html)
                result = page.evaluate(
                    "d => window.renderSlide(d)",
                    {
                        "width": width,
                        "height": height,
                        "theme": data["theme"],
                        "brand": data["brand"],
                        "slot_label": data["slot_label"],
                        "footer": data["footer"],
                        "slide": slide,
                        "fonts": fonts,
                    },
                )
                if not result.get("fits"):
                    print(f"slide {slide['index']}: text does not fit", file=sys.stderr)
                    return 2
                page.screenshot(path=str(out / f"{int(slide['index']):02d}.png"), clip={"x": 0, "y": 0, "width": width, "height": height})
        finally:
            browser.close()

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # report, never hang the queue worker
        print(f"render failed: {exc}", file=sys.stderr)
        sys.exit(1)
