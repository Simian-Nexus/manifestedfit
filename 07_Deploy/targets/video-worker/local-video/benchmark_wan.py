"""Submit a minimal Wan 2.1 benchmark to the local ComfyUI API."""
import json
import sys
import time
import urllib.request

BASE = "http://127.0.0.1:8188"
steps = int(sys.argv[1]) if len(sys.argv) > 1 else 12
frames = int(sys.argv[2]) if len(sys.argv) > 2 else 49
prompt = ("Cinematic wellness b-roll, a narrow beam of warm golden light moves "
          "across a dark wooden desk and reveals an open journal, gentle camera "
          "push-in, photorealistic, natural motion, no text, no logos")
negative = ("blurry, static image, low quality, text, watermark, logo, distorted, "
            "deformed hands, oversaturated, jitter, duplicate objects")

workflow = {
    "1": {"class_type": "UNETLoader", "inputs": {
        "unet_name": "wan2.1_t2v_1.3B_bf16.safetensors", "weight_dtype": "default"}},
    "2": {"class_type": "CLIPLoader", "inputs": {
        "clip_name": "umt5_xxl_fp8_e4m3fn_scaled.safetensors", "type": "wan", "device": "cpu"}},
    "3": {"class_type": "VAELoader", "inputs": {"vae_name": "wan_2.1_vae.safetensors"}},
    "4": {"class_type": "CLIPTextEncode", "inputs": {"text": prompt, "clip": ["2", 0]}},
    "5": {"class_type": "CLIPTextEncode", "inputs": {"text": negative, "clip": ["2", 0]}},
    "6": {"class_type": "ModelSamplingSD3", "inputs": {"model": ["1", 0], "shift": 8.0}},
    "7": {"class_type": "EmptyHunyuanLatentVideo", "inputs": {
        "width": 832, "height": 480, "length": frames, "batch_size": 1}},
    "8": {"class_type": "KSampler", "inputs": {
        "model": ["6", 0], "seed": 7302026, "steps": steps, "cfg": 6.0,
        "sampler_name": "uni_pc", "scheduler": "simple", "positive": ["4", 0],
        "negative": ["5", 0], "latent_image": ["7", 0], "denoise": 1.0}},
    "9": {"class_type": "VAEDecode", "inputs": {"samples": ["8", 0], "vae": ["3", 0]}},
    "10": {"class_type": "CreateVideo", "inputs": {"images": ["9", 0], "fps": 16.0}},
    "11": {"class_type": "SaveVideo", "inputs": {
        "video": ["10", 0], "filename_prefix": f"benchmark/wan_{steps}steps_{frames}frames",
        "format": "mp4", "codec": "h264"}},
}

def request(path, data=None):
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(BASE + path, data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as response:
        return json.load(response)

start = time.perf_counter()
submitted = request("/prompt", {"prompt": workflow})
if submitted.get("node_errors"):
    raise SystemExit(json.dumps(submitted, indent=2))
prompt_id = submitted["prompt_id"]
print(f"submitted {prompt_id}: {steps} steps, {frames} frames", flush=True)
while True:
    history = request(f"/history/{prompt_id}")
    if prompt_id in history:
        elapsed = time.perf_counter() - start
        print(json.dumps({"elapsed_seconds": round(elapsed, 1),
                          "steps": steps, "frames": frames,
                          "outputs": history[prompt_id].get("outputs", {})}, indent=2))
        break
    time.sleep(2)
