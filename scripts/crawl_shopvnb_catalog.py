#!/usr/bin/env python3
"""Crawl every product on https://shopvnb.com/san-pham into JSON for Laravel import."""
from __future__ import annotations

import hashlib
import json
import re
import time
from pathlib import Path
from urllib.parse import urljoin
from urllib.request import Request, urlopen

BASE = "https://shopvnb.com/"
LIST_URL = "https://shopvnb.com/san-pham?page={page}"
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)
OUT = Path(__file__).resolve().parent / "data" / "shopvnb_catalog.json"

BRANDS = [
    "Yonex", "Victor", "Lining", "Li-Ning", "Li Ning", "Mizuno", "Apacs",
    "Kawasaki", "Flypower", "FlyPower", "Forza", "Proace", "VNB", "VS",
    "Felet", "Babolat", "Kumpoo", "Promax", "Carlton", "Fleet", "Prokennex",
    "Kamito", "Donex", "Adidas", "FZ Forza", "Joola", "Head", "Wilson",
    "Selkirk", "Nike", "Asics", "Taro", "Victec", "Pili", "Sunbatta",
    "Wika", "Zocker", "Kaiwin", "Jogarbola", "Diadem", "Gamma", "SFD",
    "Alien", "Passion", "Beesoul", "Amakirk", "Kason", "Adonex", "Prince",
    "Tecnifibre", "Dunlop", "Gosen", "Lining",
]


def fetch(url: str) -> str:
    req = Request(url, headers={"User-Agent": UA, "Accept-Language": "vi,en;q=0.8"})
    with urlopen(req, timeout=45) as resp:
        return resp.read().decode("utf-8", "ignore")


def parse_money(text: str) -> int | None:
    text = text.replace("\xa0", " ").strip()
    nums = re.findall(r"(\d{1,3}(?:\.\d{3})+|\d+)", text)
    if not nums:
        return None
    return int(nums[0].replace(".", ""))


def detect_brand(name: str) -> str:
    lower = name.lower()
    for brand in BRANDS:
        if brand.lower() in lower:
            if brand.lower() in ("li ning", "li-ning", "lining"):
                return "Li-Ning"
            if brand.lower() == "flypower":
                return "Flypower"
            if brand == "Lining":
                return "Li-Ning"
            return brand
    return "Other"


def detect_category(name: str) -> str:
    n = name.lower()
    rules = [
        ("grip", ("quấn cán", "cuốn cán", "quan can", "cuon can", "overgrip")),
        ("string", ("cước", "cuoc", "dây đan", "day dan", "dây cước")),
        ("shuttlecock", ("quả cầu", "qua cau", "ống cầu", "hop cau", "hộp cầu")),
        ("bag", ("túi", "tui ", "balo", "bao vợt", "bao vot")),
        ("shoes", ("giày", "giay", "giầy")),
        ("shirt", ("váy", "vay ", "đầm", "dam ", "áo", "ao ")),
        ("shorts", ("quần", "quan ")),
        ("racket", ("vợt", "vot ")),
    ]
    for category, keys in rules:
        if any(key in n for key in keys):
            return category
    return "other"


def product_id(slug: str) -> str:
    digest = hashlib.sha1(slug.encode("utf-8")).hexdigest()[:10]
    return f"vnb_{digest}"


CARD_RE = re.compile(
    r'<div class="item_product_main">\s*'
    r'<div class="product-thumbnail">([\s\S]*?)</div>\s*'
    r'<div class="product-info">([\s\S]*?)</div>\s*'
    r"</div>",
    re.I,
)


def parse_products(html: str) -> list[dict]:
    products: list[dict] = []
    seen: set[str] = set()

    for thumb, info in CARD_RE.findall(html):
        card = thumb + info
        href_m = re.search(
            r'class="product_overlay"[^>]*href="([^"]+)"[^>]*(?:title="([^"]*)")?',
            card,
        )
        if not href_m:
            continue
        href = href_m.group(1)
        title = (href_m.group(2) or "").strip()
        if not title:
            name_m = re.search(
                r'class="product-name"><a[^>]*(?:title="([^"]*)")?[^>]*>([^<]*)</a>',
                info,
            )
            if name_m:
                title = (name_m.group(1) or name_m.group(2) or "").strip()
        if not title:
            continue

        slug = href.split("/")[-1].replace(".html", "")
        if slug in seen:
            continue
        seen.add(slug)

        img_m = re.search(
            r'(?:data-src|src)="(https://cdn\.shopvnb\.com[^"]+)"',
            thumb,
        )
        image = img_m.group(1) if img_m else None
        if image:
            image = re.sub(r"/img/\d+x\d+/", "/", image)

        price_m = re.search(r'class="price">([^<]+)</span>', info)
        compare_m = re.search(r'class="compare-price">([^<]+)</span>', info)
        price = parse_money(price_m.group(1)) if price_m else None
        original = parse_money(compare_m.group(1)) if compare_m else None
        if price is None:
            continue
        if original is not None and original <= price:
            original = None

        products.append(
            {
                "id": product_id(slug),
                "slug": slug,
                "source_url": urljoin(BASE, href),
                "name": title,
                "brand": detect_brand(title),
                "category": detect_category(title),
                "price": price,
                "original_price": original,
                "image": image,
                "images": [image] if image else [],
                "stock": 25,
                "rating": 0,
                "reviews": 0,
                "description": title,
                "featured": False,
                "sold_count": 0,
            }
        )
    return products


def write_payload(all_items: dict[str, dict]) -> None:
    rows = sorted(all_items.values(), key=lambda x: (x["category"], -x["price"], x["name"]))
    payload = {
        "source": "https://shopvnb.com/san-pham",
        "count": len(rows),
        "products": rows,
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")


def main() -> None:
    OUT.parent.mkdir(parents=True, exist_ok=True)
    all_items: dict[str, dict] = {}
    page = 1
    stagnant = 0

    while page <= 2500:
        url = LIST_URL.format(page=page)
        print(f"fetch page {page}", flush=True)
        try:
            html = fetch(url)
        except Exception as exc:
            print(f"  error {exc} — retry once", flush=True)
            time.sleep(2)
            html = fetch(url)
        items = parse_products(html)
        new_count = 0
        for item in items:
            if item["slug"] not in all_items:
                all_items[item["slug"]] = item
                new_count += 1
        print(f"  parsed={len(items)} new={new_count} total={len(all_items)}", flush=True)
        if new_count == 0:
            stagnant += 1
            if stagnant >= 2:
                print("no new products for 2 pages — stop", flush=True)
                break
        else:
            stagnant = 0
        if page % 25 == 0:
            write_payload(all_items)
            print(f"  checkpoint {len(all_items)}", flush=True)
        page += 1
        time.sleep(0.25)

    write_payload(all_items)
    print(f"wrote {len(all_items)} products -> {OUT}", flush=True)
    counts: dict[str, int] = {}
    for row in all_items.values():
        counts[row["category"]] = counts.get(row["category"], 0) + 1
    print("categories:", dict(sorted(counts.items(), key=lambda x: -x[1])), flush=True)


if __name__ == "__main__":
    main()
