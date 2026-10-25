@echo off
cd /d %~dp0
echo ============================================
echo   Universal Remote - Home Agent
echo   Keep this window open while using the
echo   remote website. Close it to stop.
echo ============================================
python agent.py
pause
