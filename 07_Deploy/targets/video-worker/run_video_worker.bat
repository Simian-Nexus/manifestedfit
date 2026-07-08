@echo off
rem One-click Manifested Fit video run: render -> upload -> wait for
rem Telegram approval -> publish + embed. Safe to double-click any time.
cd /d %~dp0
venv\Scripts\python.exe video_worker.py --watch
pause
