"""Build the branded intro clip and end card used by video_worker.

Inputs (in branding/):
  logo.png    - required
  jingle.mp3  - optional 3s audio sting; silence is used until it exists.
                Re-run this script after adding or changing it.

Outputs (in branding/):
  intro.mp4   - 3s animated logo intro, 1920x1080@30 with audio track
  endcard.png - 1920x1080 like/subscribe end card

Usage: venv\\Scripts\\python.exe build_branding.py
"""

import os
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(HERE, "branding")
LOGO = os.path.join(BRAND, "logo.png")
JINGLE = os.path.join(BRAND, "jingle.mp3")

# dark blend of the logo's purple->teal gradient
GRADIENT = "gradients=s=1920x1080:c0=0x2a1e4f:c1=0x0e3b3c:x0=200:y0=100:x1=1700:y1=1000"


def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise SystemExit(f"ffmpeg failed:\n{p.stderr[-2000:]}")


def build_intro():
    out = os.path.join(BRAND, "intro.mp4")
    if os.path.exists(JINGLE):
        audio_in = ["-i", JINGLE]
        audio_f = "[2:a]atrim=0:3,afade=t=out:st=2.4:d=0.6,aresample=44100,aformat=channel_layouts=stereo[a]"
    else:
        audio_in = ["-f", "lavfi", "-i", "anullsrc=r=44100:cl=stereo"]
        audio_f = "[2:a]atrim=0:3[a]"
    fc = (
        "[1:v]scale=520:-1,format=rgba,"
        "fade=in:st=0:d=0.7:alpha=1,fade=out:st=2.5:d=0.5:alpha=1[lg];"
        "[0:v][lg]overlay=(W-w)/2:(H-h)/2-80[v1];"
        "[v1]drawtext=text='Manifested Fit':fontcolor=white:fontsize=84:"
        "x=(w-tw)/2:y=h/2+260:"
        "alpha='if(lt(t,1),0,if(lt(t,1.6),(t-1)/0.6,if(lt(t,2.5),1,(3-t)/0.5)))'[v];"
        + audio_f
    )
    run(["ffmpeg", "-y",
         "-f", "lavfi", "-t", "3", "-i", GRADIENT + ":d=3,fps=30",
         "-loop", "1", "-t", "3", "-i", LOGO,
         *audio_in,
         "-filter_complex", fc, "-map", "[v]", "-map", "[a]", "-t", "3",
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
         "-c:a", "aac", "-b:a", "160k", "-pix_fmt", "yuv420p", out])
    print("built", out, "(jingle:", os.path.exists(JINGLE), ")")


def build_endcard():
    out = os.path.join(BRAND, "endcard.png")
    fc = (
        "[1:v]scale=440:-1[lg];"
        "[0:v][lg]overlay=(W-w)/2:140[v1];"
        "[v1]drawtext=text='Manifested Fit':fontcolor=white:fontsize=76:"
        "x=(w-tw)/2:y=660,"
        "drawtext=text='Like  •  Subscribe':fontcolor=white:fontsize=58:"
        "x=(w-tw)/2:y=790,"
        "drawtext=text='manifestedfit.com':fontcolor=white@0.75:fontsize=44:"
        "x=(w-tw)/2:y=920[v]"
    )
    run(["ffmpeg", "-y",
         "-f", "lavfi", "-i", GRADIENT,
         "-i", LOGO,
         "-filter_complex", fc, "-map", "[v]", "-frames:v", "1", out])
    print("built", out)


if __name__ == "__main__":
    if not os.path.exists(LOGO):
        raise SystemExit(f"Missing {LOGO}")
    build_intro()
    build_endcard()
