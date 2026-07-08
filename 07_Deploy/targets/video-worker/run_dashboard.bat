@echo off
rem Manifested Fit video pipeline dashboard - http://localhost:8765
cd /d %~dp0
start "" http://localhost:8765
venv\Scripts\python.exe dashboard.py
