"""Build the branded intro clip and end-card assets used by video_worker.

Inputs (in branding/):
  logo.png    - required
  jingle.mp3  - optional audio sting; silence is used until it exists.
                Re-run this script after adding or changing it.

Outputs (in branding/):
  intro.mp4    - 8s animated logo intro, 1920x1080@30 with audio track
  endcard.mp4  - 12s animated like/subscribe end card (cursor clicks
                 Subscribe, then the bell, which swings; silent - the worker
                 adds the ding.mp3 in sync). Last frame holds cleanly, so the
                 worker can extend it to any outro length.
  endcard.png  - static fallback end card (used if endcard.mp4 is missing)
  ding.mp3     - two-tone bell "ding ding" the worker mixes under the bell
                 animation
  bell.png / cursor.png / sub.png / sub_pressed.png - overlay sprites drawn
                 with Pillow, consumed by the endcard build (kept for reruns)

Usage: venv\\Scripts\\python.exe build_branding.py
"""

import os
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.join(HERE, "branding")
LOGO = os.path.join(BRAND, "logo.png")
JINGLE = os.path.join(BRAND, "jingle.mp3")

INTRO_SECONDS = 8      # was 3; longer hold lets the jingle breathe
ENDCARD_SECONDS = 12   # animation happens in the first ~4s, then holds

# dark blend of the logo's purple->teal gradient
GRADIENT = "gradients=s=1920x1080:c0=0x2a1e4f:c1=0x0e3b3c:x0=200:y0=100:x1=1700:y1=1000"


def run(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise SystemExit(f"ffmpeg failed:\n{p.stderr[-2000:]}")


# ---------------------------------------------------------------- sprites

def _font(size):
    from PIL import ImageFont
    for name in ("arialbd.ttf", "arial.ttf"):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def make_bell(path):
    """Golden notification bell, 256x256, transparent background."""
    from PIL import Image, ImageDraw
    img = Image.new("RGBA", (256, 256), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    gold, dark = (245, 197, 66, 255), (168, 128, 28, 255)
    d.ellipse((114, 26, 142, 54), fill=gold, outline=dark, width=4)      # knob
    d.pieslice((58, 42, 198, 182), 180, 360, fill=gold, outline=dark, width=4)  # dome
    d.polygon([(58, 112), (198, 112), (216, 178), (40, 178)], fill=gold, outline=dark)  # flare
    d.rounded_rectangle((34, 174, 222, 196), radius=11, fill=gold, outline=dark, width=4)  # lip
    d.ellipse((110, 198, 146, 234), fill=gold, outline=dark, width=4)    # clapper
    img.save(path)


def make_cursor(path):
    """Classic white arrow pointer with dark outline, 96x96."""
    from PIL import Image, ImageDraw
    img = Image.new("RGBA", (96, 96), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    pts = [(12, 6), (12, 76), (30, 60), (42, 88), (56, 82), (44, 56), (70, 56)]
    d.polygon(pts, fill=(255, 255, 255, 255), outline=(25, 25, 25, 255), width=3)
    img.save(path)


def make_subscribe(path, pressed):
    """YouTube-red SUBSCRIBE pill, 520x120; pressed variant is darker."""
    from PIL import Image, ImageDraw
    img = Image.new("RGBA", (520, 120), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    red = (153, 10, 10, 255) if pressed else (212, 22, 22, 255)
    d.rounded_rectangle((4, 4, 516, 116), radius=24, fill=red,
                        outline=(255, 255, 255, 60), width=2)
    f = _font(52)
    d.text((260, 60), "SUBSCRIBE", font=f, fill=(255, 255, 255, 255), anchor="mm")
    img.save(path)


# ------------------------------------------------------------------ intro

def build_intro():
    out = os.path.join(BRAND, "intro.mp4")
    T = INTRO_SECONDS
    if os.path.exists(JINGLE):
        audio_in = ["-i", JINGLE]
        audio_f = (f"[2:a]atrim=0:{T},afade=t=out:st={T - 1.4}:d=1.4,"
                   "aresample=44100,aformat=channel_layouts=stereo[a]")
    else:
        audio_in = ["-f", "lavfi", "-i", "anullsrc=r=44100:cl=stereo"]
        audio_f = f"[2:a]atrim=0:{T}[a]"
    # Logo: soft glow layer underneath, gentle vertical float, long fades.
    # Title fades in at 1s, tagline at 2s; everything eases out at the end.
    fc = (
        "[1:v]scale=560:-1,format=rgba,"
        f"fade=in:st=0:d=1.0:alpha=1,fade=out:st={T - 1.0}:d=0.9:alpha=1,split[lga][lgb];"
        "[lga]gblur=sigma=24,colorchannelmixer=aa=0.5[glow];"
        "[0:v][glow]overlay=(W-w)/2:(H-h)/2-80+8*sin(2*PI*t/4)[v0];"
        "[v0][lgb]overlay=(W-w)/2:(H-h)/2-80+8*sin(2*PI*t/4)[v1];"
        "[v1]drawtext=text='Manifested Fit':fontcolor=white:fontsize=92:"
        "x=(w-tw)/2:y=h/2+240:"
        f"alpha='if(lt(t,1),0,if(lt(t,1.8),(t-1)/0.8,if(lt(t,{T - 1.0}),1,({T - 0.2}-t)/0.8)))'[v2];"
        "[v2]drawtext=text='Manifest your calm. Move with intention.':"
        "fontcolor=white@0.85:fontsize=40:x=(w-tw)/2:y=h/2+360:"
        f"alpha='if(lt(t,2),0,if(lt(t,2.8),(t-2)/0.8,if(lt(t,{T - 1.0}),1,({T - 0.4}-t)/0.6)))'[v3];"
        f"[v3]fade=out:st={T - 0.6}:d=0.6[v];"
        + audio_f
    )
    run(["ffmpeg", "-y",
         "-f", "lavfi", "-t", str(T), "-i", GRADIENT + f":d={T},fps=30",
         "-loop", "1", "-t", str(T), "-i", LOGO,
         *audio_in,
         "-filter_complex", fc, "-map", "[v]", "-map", "[a]", "-t", str(T),
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
         "-c:a", "aac", "-b:a", "160k", "-pix_fmt", "yuv420p", out])
    print("built", out, f"({T}s, jingle: {os.path.exists(JINGLE)})")


# ---------------------------------------------------------------- endcard

def build_endcard_png():
    """Static fallback end card (used only if endcard.mp4 is missing)."""
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


def build_endcard_video():
    """Animated end card: cursor clicks SUBSCRIBE (1.0s), slides to the bell
    and clicks it (2.0s), the bell swings ding-ding style. The worker mixes
    ding.mp3 at outro-start + 2.0s so sound and swing line up."""
    out = os.path.join(BRAND, "endcard.mp4")
    T = ENDCARD_SECONDS
    bell = os.path.join(BRAND, "bell.png")
    cursor = os.path.join(BRAND, "cursor.png")
    sub = os.path.join(BRAND, "sub.png")
    subp = os.path.join(BRAND, "sub_pressed.png")

    # Button row: SUBSCRIBE (520 wide) + gap + bell (120) centered -> x0=610.
    sub_x, sub_y = 610, 730
    bell_cx, bell_cy = 1250, 790  # bell canvas is padded to 200x200
    # Cursor tip path: idle bottom-right -> button (0.3-1.0) -> bell (1.3-1.9).
    cur_x = ("if(lt(t,0.3),1500,if(lt(t,1.0),1500+(870-1500)*(t-0.3)/0.7,"
             "if(lt(t,1.3),870,if(lt(t,1.9),870+(1250-870)*(t-1.3)/0.6,1250))))")
    cur_y = ("if(lt(t,0.3),1000,if(lt(t,1.0),1000+(790-1000)*(t-0.3)/0.7,790))")
    # Damped swing after the bell click at t=2.0.
    swing = "if(gt(t,2.0),0.5*sin(12*(t-2.0))*exp(-1.8*(t-2.0)),0)"

    fc = (
        "[1:v]scale=400:-1[lg];"
        "[0:v][lg]overlay=(W-w)/2:110[b1];"
        "[b1]drawtext=text='Manifested Fit':fontcolor=white:fontsize=76:x=(w-tw)/2:y=600,"
        "drawtext=text='Enjoyed this? One tap keeps them coming.':fontcolor=white@0.85:"
        "fontsize=40:x=(w-tw)/2:y=672,"
        "drawtext=text='manifestedfit.com':fontcolor=white@0.75:fontsize=44:x=(w-tw)/2:y=950[b2];"
        f"[2:v][3:v]overlay=0:0:enable='between(t,1.0,1.25)'[subbtn];"
        f"[b2][subbtn]overlay={sub_x}:{sub_y}[b3];"
        "[4:v]scale=120:-1,pad=200:200:40:40:color=0x00000000,format=rgba,"
        f"rotate=a='{swing}':c=none[bellr];"
        f"[b3][bellr]overlay={bell_cx - 100}:{bell_cy - 100}[b4];"
        "[5:v]scale=52:-1[cur];"
        f"[b4][cur]overlay=x='{cur_x}':y='{cur_y}':enable='lt(t,3.6)',fade=in:d=0.5[v]"
    )
    run(["ffmpeg", "-y",
         "-f", "lavfi", "-t", str(T), "-i", GRADIENT + f":d={T},fps=30",
         "-loop", "1", "-t", str(T), "-i", LOGO,
         "-loop", "1", "-t", str(T), "-i", sub,
         "-loop", "1", "-t", str(T), "-i", subp,
         "-loop", "1", "-t", str(T), "-i", bell,
         "-loop", "1", "-t", str(T), "-i", cursor,
         "-filter_complex", fc, "-map", "[v]", "-t", str(T), "-an",
         "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
         "-pix_fmt", "yuv420p", out])
    print("built", out, f"({T}s)")


def build_ding():
    """Bright two-hit bell 'ding ding' (~1.8s) for the worker to mix in."""
    out = os.path.join(BRAND, "ding.mp3")
    hit = ("aevalsrc='0.55*exp(-5*t)*sin(2*PI*1760*t)"
           "+0.2*exp(-7*t)*sin(2*PI*3520*t)':d=1.4:s=44100")
    run(["ffmpeg", "-y", "-f", "lavfi", "-i", hit,
         "-filter_complex",
         "[0:a]asplit[a1][a2];[a2]adelay=350|350[a2d];"
         "[a1][a2d]amix=inputs=2:normalize=0,aformat=channel_layouts=stereo[a]",
         "-map", "[a]", "-b:a", "160k", out])
    print("built", out)


if __name__ == "__main__":
    if not os.path.exists(LOGO):
        raise SystemExit(f"Missing {LOGO}")
    make_bell(os.path.join(BRAND, "bell.png"))
    make_cursor(os.path.join(BRAND, "cursor.png"))
    make_subscribe(os.path.join(BRAND, "sub.png"), pressed=False)
    make_subscribe(os.path.join(BRAND, "sub_pressed.png"), pressed=True)
    build_intro()
    build_endcard_video()
    build_endcard_png()
    build_ding()
