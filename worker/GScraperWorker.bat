@echo off
cd /d "%~dp0"
title GScraper worker
if not exist node_modules (
  echo First run: installing dependencies...
  call npm install || goto :fail
  call npx playwright install chromium || goto :fail
)
if not exist .env (
  echo Missing .env file. Copy .env.example to .env and set SERVER_URL and WORKER_TOKEN.
  goto :fail
)
echo Starting GScraper worker... close this window to stop it.
node src\worker.js
:fail
pause
