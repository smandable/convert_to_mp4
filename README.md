# convert_to_mp4

A PHP CLI tool that batch-converts video files to MP4 (H.264/AAC). Runs in the current directory, processes each file with a real-time progress bar, and validates the output before deleting the source.

## Features

- **Smart codec handling** — copies H.264/HEVC/MPEG4 video streams directly when MP4-compatible; re-encodes everything else via libx264
- **Audio preservation** — copies AAC/MP3/ALAC audio; transcodes other codecs to AAC (with bitrate clamping) or ALAC
- **Corruption detection** — multi-point decode checks before and after conversion; quarantines bad files to a `_corrupt` folder
- **Junk/static detection** — entropy + luma analysis to identify and quarantine snow/static video (common with WMV/AVI)
- **Interlace detection** — automatic deinterlacing (yadif) for interlaced sources via ffmpeg's idet filter
- **Output validation** — verifies the output has a video stream, sane duration, and decodable samples at multiple points
- **Progress display** — real-time progress bar with percentage, time, size, and speed
- **macOS sleep prevention** — uses `caffeinate` to keep the system awake during long runs
- **Temp directory support** — write temp files to a fast SSD, then move to the destination
- **Preserves timestamps** — output files retain the original modification time

## Usage

```bash
cd /path/to/your/videos
php /path/to/convert_to_mp4.php
```

### Options

| Flag | Description |
|---|---|
| `--dry-run` | Preview what would happen without converting |
| `--keep` | Keep original files after successful conversion |
| `--recursive` | Recurse into subdirectories (skips hidden dirs like `.git`/`.Trash`) |
| `--bitrate=NNNk` | AAC audio bitrate cap (default: `256k`) |
| `--audio=aac\|alac` | Audio transcode codec (default: `aac`) |
| `--preset=NAME` | x264 preset (default: `medium`) |
| `--vbitrate=KBPS` | Force video bitrate for re-encodes |
| `--deint=auto\|on\|off` | Deinterlace control (default: `auto`) |
| `--max-grow-mb=MB` | Cap output growth vs input (default: `1000`) |
| `--junk=auto\|on\|off` | Junk/static video detection (default: `auto`) |
| `--temp-dir=PATH` | Write temp files to this directory |
| `--ffmpeg-timeout-min=N` | Timeout per file in minutes (default: `30`) |
| `--verbose-errors` | Print full ffmpeg stderr on failures |
| `--help` | Show all options |

### Junk Detection Tuning

| Flag | Description |
|---|---|
| `--junk-entropy=F` | Entropy threshold, 0-8 (default: `7.70`) |
| `--junk-ystd=F` | Luma std-dev threshold (default: `55`) |
| `--junk-sample-fps=N` | Sample FPS for analysis (default: `2`) |
| `--junk-timeout=S` | Timeout per sample probe (default: `25s`) |

## Supported Input Formats

`mkv`, `wmv`, `mov`, `avi`, `m4v`, `mpg`, `mpeg`, `vob`, `webm`, `flv`, `ts`, `m2ts`

## How It Works

1. Scans the current directory for supported video files (or the full tree with `--recursive`)
2. Probes each file with ffprobe for codec, resolution, and duration
3. Runs a multi-point decode check to catch corruption early
4. Optionally checks for junk/static video (entropy + luma analysis)
5. Converts to MP4 with appropriate codec decisions (copy vs re-encode)
6. Validates the output (stream presence, duration, decode samples)
7. Preserves the original modification timestamp
8. Deletes the source file (unless `--keep`)
9. Prints a summary table at the end

Corrupt or junk files are moved to a `_corrupt` subdirectory rather than deleted.

## Requirements

- PHP 8.0+
- [FFmpeg](https://ffmpeg.org/) (`ffmpeg` and `ffprobe` must be on your PATH)

## License

MIT
