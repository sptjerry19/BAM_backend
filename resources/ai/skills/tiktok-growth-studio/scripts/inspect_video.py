#!/usr/bin/env python3
"""Read-only technical media check. Editorial quality and rights remain unverified."""
import argparse
import hashlib
import json
import math
import shutil
import subprocess
import sys
from fractions import Fraction
from pathlib import Path


def run(command, timeout):
    return subprocess.run(command, capture_output=True, text=True, encoding="utf-8",
                          errors="replace", timeout=timeout, check=False)


def inspect(path, ffprobe, ffmpeg):
    report = {"file": str(path.resolve()), "technical_status": "fail",
              "editorial_review_required": True, "rights_verified": False,
              "errors": [], "warnings": []}
    if not path.is_file():
        report["errors"].append("Media file does not exist.")
        return report
    if not ffprobe:
        report["errors"].append("ffprobe unavailable; supply --ffprobe.")
        return report
    if not ffmpeg:
        report["errors"].append("ffmpeg unavailable; full decode cannot be checked.")
        return report
    try:
        probe = run([ffprobe, "-v", "error", "-show_streams", "-show_format", "-of", "json", str(path)], 60)
        if probe.returncode:
            report["errors"].append("ffprobe rejected media: " + probe.stderr[-1500:])
            return report
        info = json.loads(probe.stdout)
        streams = info.get("streams", [])
        videos = [s for s in streams if s.get("codec_type") == "video" and not s.get("disposition", {}).get("attached_pic")]
        if len(videos) != 1:
            report["errors"].append("Expected one video stream.")
            return report
        video = videos[0]
        width, height = int(video.get("width", 0)), int(video.get("height", 0))
        duration = float(info.get("format", {}).get("duration", 0))
        fps = float(Fraction(video.get("avg_frame_rate", "0/1")))
        report.update({"width": width, "height": height, "duration_seconds": duration,
                       "fps": fps, "video_codec": video.get("codec_name"),
                       "pixel_format": video.get("pix_fmt"),
                       "audio_codecs": [s.get("codec_name") for s in streams if s.get("codec_type") == "audio"],
                       "size_bytes": path.stat().st_size})
        if not math.isfinite(duration) or duration <= 0:
            report["errors"].append("Invalid duration.")
        if not math.isfinite(fps) or fps <= 0:
            report["errors"].append("Invalid frame rate.")
        if width < 720 or height < 1280 or abs(width / max(height, 1) - 9 / 16) > 0.02:
            report["errors"].append("Expected vertical 9:16 video at least 720 x 1280.")
        if "mp4" not in info.get("format", {}).get("format_name", ""):
            report["errors"].append("Expected MP4 container for this publishing workflow.")
        if video.get("codec_name") != "h264" or video.get("pix_fmt") != "yuv420p":
            report["errors"].append("Expected H.264 / yuv420p for this publishing workflow.")
        if fps < 24:
            report["warnings"].append("Frame rate below 24 fps; inspect motion.")
        if not report["audio_codecs"]:
            report["warnings"].append("No audio track. Confirm this is an editorial choice.")
        else:
            report["warnings"].append("Audio track exists; audibility and quality require listening.")
        if duration > 60:
            report["warnings"].append("Over 60 seconds: check pacing and current platform limits.")
        decoded = run([ffmpeg, "-v", "error", "-xerror", "-i", str(path), "-f", "null", "-"],
                      min(900, max(60, int(duration * 4 + 30))) if math.isfinite(duration) and duration > 0 else 60)
        if decoded.returncode:
            report["errors"].append("Full decode failed: " + decoded.stderr[-1500:])
        else:
            report["full_decode"] = "pass"
        digest = hashlib.sha256()
        with path.open("rb") as source:
            for block in iter(lambda: source.read(1024 * 1024), b""):
                digest.update(block)
        report["sha256"] = digest.hexdigest()
    except (OSError, ValueError, ZeroDivisionError, subprocess.TimeoutExpired) as exc:
        report["errors"].append(str(exc))
    if not report["errors"]:
        report["technical_status"] = "pass"
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("video", type=Path)
    parser.add_argument("--ffprobe", default=shutil.which("ffprobe"))
    parser.add_argument("--ffmpeg", default=shutil.which("ffmpeg"))
    args = parser.parse_args()
    report = inspect(args.video, args.ffprobe, args.ffmpeg)
    print(json.dumps(report, ensure_ascii=False, indent=2, allow_nan=False))
    return 0 if report["technical_status"] == "pass" else 1


if __name__ == "__main__":
    sys.exit(main())
