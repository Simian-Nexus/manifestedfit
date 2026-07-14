# Local AI video backend

Hybrid mode uses local Wan 2.1 T2V 1.3B through a private ComfyUI server.
Generation has no per-clip API fee; it uses the local RTX 3060, electricity,
and render time.

## Installed profile

- GPU: RTX 3060, 12 GB VRAM
- Model: Wan 2.1 T2V 1.3B, Apache-2.0
- Resolution: 832x480
- Frames: 49 at 16 fps (3.06 seconds raw)
- Sampling: 20 steps, UniPC/simple, CFG 6, shift 8
- Production cap: 2 locally generated beats per video
- Raw clips are slowed to fill their narration beats, then normalized/upscaled
  by the existing 1080p renderer.

## Benchmark (2026-07-11)

| Profile | Raw duration | Wall time | Peak observed VRAM | Result |
|---|---:|---:|---:|---|
| 12 steps / 49 frames | 3.06s | 3m 33s | ~6.25 GB | Coherent, more object duplication |
| 20 steps / 49 frames | 3.06s | 4m 03s | ~6.1 GB | Cleaner and preferred |

Two 20-step clips should take about eight minutes while the model/server stays
warm, meeting the desired approximate ten-minute local-generation budget.

## Operation

Set these in `config.json` (also exposed in the dashboard):

```json
{
  "visuals_engine": "hybrid",
  "generated_video_provider": "local_wan",
  "local_wan_max_clips_per_video": 2,
  "local_wan_steps": 20,
  "local_wan_frames": 49,
  "local_wan_timeout_minutes": 12
}
```

The worker starts ComfyUI automatically on `127.0.0.1:8188` when needed with
paid API nodes disabled. Normal resumability applies to completed local clips.
Do not run two workers simultaneously.

Use `benchmark_wan.py <steps> <frames>` only while ComfyUI is already running.

## Seedance note

Seedance is closed-weight and cannot run on this hardware. Free hosted credits
may be useful for manual one-off clips, but they are not treated as a stable,
unattended backend. Local Wan is the default free automation path; paid Veo
remains selectable in the dashboard.
