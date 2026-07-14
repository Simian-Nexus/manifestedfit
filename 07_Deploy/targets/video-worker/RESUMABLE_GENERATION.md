# Resumable hybrid-video protocol

The worker keeps one persistent workspace per WordPress post under
`work/post_<ID>/`. Normal reruns resume automatically.

## Normal operation

1. Start the dashboard with `run_dashboard.bat`.
2. Leave **Fresh restart** unchecked.
3. Run the pipeline with approval waiting.
4. If the computer, terminal, or worker stops, run the same post again. The
   worker reuses completed voiceovers, the Gemini hybrid plan, downloaded
   stock clips, completed Veo clips, and an already-uploaded YouTube preview.
5. If interruption happened while Veo was generating a beat, the saved Google
   operation name is polled again instead of submitting and charging for a
   second job.
6. Approve the separate video-preview message in Telegram. The worker then
   makes the video public and embeds it in the post.

Command-line equivalent:

```powershell
venv\Scripts\python.exe -u video_worker.py --post 73 --watch
```

## When to use Fresh restart

Use **Fresh restart** (or `--fresh`) only when you intentionally want new
creative output, such as after changing the script, voice, persona, model, or
visual direction, or after rejecting a preview. It deletes that post's cached
generation workspace before submitting new jobs.

```powershell
venv\Scripts\python.exe -u video_worker.py --post 73 --watch --fresh
```

The worker also invalidates the cache automatically when generation inputs in
the job fingerprint change. A remotely rejected video always starts fresh.

## Interruption rules

- Prefer stopping between beat-completion messages when convenient.
- A forced stop is safe: completed files and submitted Veo operation IDs are
  written immediately.
- Never select **Fresh restart** merely because a previous run was interrupted.
- Do not run two workers for the same post simultaneously; both could submit
  the same missing beat before either records it.
- Keep `work/` local and untracked. It may contain large generated media.

## Storage cleanup

Workspaces are deliberately retained after upload so approval-stage restarts
cannot duplicate work. Once a video is confirmed public and embedded, its
`work/post_<ID>/` folder may be deleted manually if disk space is needed.
