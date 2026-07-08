"""One-time YouTube OAuth bootstrap for the Manifested Fit video worker.

Prereqs:
  pip install google-auth-oauthlib google-api-python-client

Usage:
  1. Download the OAuth "Desktop app" credentials JSON from Google Cloud
     Console and save it in this folder as client_secret.json.
  2. python youtube_auth.py
     A browser window opens; sign in with the Google account that owns the
     Manifested Fit YouTube channel and approve.
  3. youtube_token.json is written here (gitignored). The worker refreshes
     it automatically from then on — no more interactive logins.
"""

import json
import os

from google_auth_oauthlib.flow import InstalledAppFlow

HERE = os.path.dirname(os.path.abspath(__file__))
CLIENT_SECRET = os.path.join(HERE, "client_secret.json")
TOKEN_FILE = os.path.join(HERE, "youtube_token.json")

# upload = videos.insert; force-ssl = videos.update (flip unlisted -> public)
SCOPES = [
    "https://www.googleapis.com/auth/youtube.upload",
    "https://www.googleapis.com/auth/youtube.force-ssl",
]


def main():
    if not os.path.exists(CLIENT_SECRET):
        raise SystemExit(
            f"Missing {CLIENT_SECRET}\n"
            "Download the OAuth Desktop-app credentials JSON from Google "
            "Cloud Console and save it there first."
        )
    flow = InstalledAppFlow.from_client_secrets_file(CLIENT_SECRET, SCOPES)
    creds = flow.run_local_server(port=0, access_type="offline", prompt="consent")
    with open(TOKEN_FILE, "w", encoding="utf-8") as f:
        f.write(creds.to_json())
    has_refresh = bool(json.loads(creds.to_json()).get("refresh_token"))
    print(f"Saved {TOKEN_FILE} (refresh token present: {has_refresh})")
    if not has_refresh:
        print(
            "WARNING: no refresh token — remove this app's access at "
            "https://myaccount.google.com/permissions and run again."
        )


if __name__ == "__main__":
    main()
