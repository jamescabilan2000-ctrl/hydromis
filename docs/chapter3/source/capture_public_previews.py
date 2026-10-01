"""Capture public HydroMIS markup without executing application PHP or databases.

Run from the repository root with Python, Pillow, websockets and Chrome installed.
The generated HTML copies add only a base URI; home.php's PHP bootstrap is omitted.
"""
from __future__ import annotations

import asyncio
import base64
import hashlib
import json
import shutil
import subprocess
import time
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

import websockets


ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "docs/chapter3/source"
ASSETS = ROOT / "docs/chapter3/assets"
CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")


class CDP:
    def __init__(self, ws):
        self.ws = ws
        self.seq = 0
        self.failed_resources = []

    async def call(self, method, params=None):
        self.seq += 1
        message_id = self.seq
        await self.ws.send(json.dumps({"id": message_id, "method": method, "params": params or {}}))
        while True:
            message = json.loads(await asyncio.wait_for(self.ws.recv(), 30))
            if message.get("method") == "Network.loadingFailed":
                self.failed_resources.append(message["params"].get("errorText", "unknown"))
            if message.get("id") == message_id:
                if "error" in message:
                    raise RuntimeError(message["error"])
                return message.get("result", {})

    async def evaluate(self, expression):
        result = await self.call("Runtime.evaluate", {
            "expression": expression, "returnByValue": True, "awaitPromise": True,
        })
        return result.get("result", {}).get("value")


def prepare_page(name):
    source = ROOT / name
    html = source.read_text(encoding="utf-8-sig")
    modifications = ["Added a base URI so copied HTML resolves original local assets."]
    if name == "home.php":
        assert html.startswith("<?php")
        html = html.split("?>", 1)[1].lstrip()
        modifications.append("Omitted the initial PHP session/database bootstrap; no PHP was executed.")
    assert "<?php" not in html and "<?=" not in html
    html = html.replace("<head>", '<head>\n    <base href="' + ROOT.as_uri() + '/">', 1)
    target = SOURCE / (Path(name).stem + "_public_preview.html")
    target.write_text(html, encoding="utf-8")
    return target, modifications, hashlib.sha256(source.read_bytes()).hexdigest()


async def capture(ws_url, pages):
    items = []
    async with websockets.connect(ws_url, max_size=30_000_000) as ws:
        cdp = CDP(ws)
        await cdp.call("Page.enable")
        await cdp.call("Network.enable")
        await cdp.call("Emulation.setDeviceMetricsOverride", {
            "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
        })
        for filename, title, source_name, expression in [
            ("preview_onboarding.png", "Public welcome and onboarding page", "onboarding.php", None),
            ("preview_home.png", "Public home page and navigation", "home.php", None),
            ("preview_portals.png", "Public home page: portal overview", "home.php",
             "window.scrollTo({top:document.querySelector('#features').offsetTop - 95, behavior:'instant'})"),
        ]:
            target, modifications, digest = pages[source_name]
            cdp.failed_resources.clear()
            await cdp.call("Page.navigate", {"url": target.as_uri()})
            await asyncio.sleep(4)
            await cdp.evaluate("Promise.race([document.fonts.ready.then(()=>true), new Promise(r=>setTimeout(()=>r(false),5000))])")
            if expression:
                await cdp.evaluate(expression)
                await asyncio.sleep(1.5)
            page_details = await cdp.evaluate("({title:document.title,bodyWidth:document.body.scrollWidth,viewportWidth:innerWidth,images:Array.from(document.images).map(i=>({source:i.getAttribute('src'),loaded:i.complete&&i.naturalWidth>0})),fontFaces:Array.from(document.fonts).map(f=>({family:f.family,status:f.status}))})")
            result = await cdp.call("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})
            (ASSETS / filename).write_bytes(base64.b64decode(result["data"]))
            items.append({
                "file": "assets/" + filename, "title": title, "source_page": source_name,
                "source_sha256": digest, "classification": "source-rendered public UI preview",
                "capture_method": "Headless Chrome; isolated browser profile; local HTML copy; original application CSS, images and JavaScript.",
                "modifications": modifications, "viewport": {"width":1440,"height":1000},
                "page_details": page_details, "resource_failures": cdp.failed_resources[:],
                "limitations": [
                    "This is a public UI source preview, not an authenticated/live database session.",
                    "No account, order, report or delivery record was created or displayed.",
                    "Visible product statements are interface text, not measured research results.",
                ],
            })
    return items


def main():
    SOURCE.mkdir(parents=True, exist_ok=True)
    ASSETS.mkdir(parents=True, exist_ok=True)
    pages = {name: prepare_page(name) for name in ["onboarding.php", "home.php"]}
    profile = SOURCE / ".capture-profile"
    if profile.exists():
        raise RuntimeError("A capture profile already exists; inspect it before running again.")
    process = subprocess.Popen([
        str(CHROME), "--headless=new", "--disable-gpu", "--no-first-run",
        "--no-default-browser-check", "--disable-background-networking",
        "--remote-debugging-address=127.0.0.1", "--remote-debugging-port=0",
        "--user-data-dir=" + str(profile), "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        creationflags=subprocess.CREATE_NO_WINDOW)
    try:
        active_port = profile / "DevToolsActivePort"
        deadline = time.monotonic() + 20
        while not active_port.exists() and time.monotonic() < deadline:
            time.sleep(.1)
        port = active_port.read_text().splitlines()[0]
        with urllib.request.urlopen("http://127.0.0.1:" + port + "/json") as response:
            targets = json.load(response)
        ws_url = next(t["webSocketDebuggerUrl"] for t in targets if t["type"] == "page")
        previews = asyncio.run(capture(ws_url, pages))
        manifest = {
            "generated_at_utc": datetime.now(timezone.utc).isoformat(),
            "application": "HydroMIS", "previews": previews,
            "privacy_and_method": "No application PHP, configuration, database, credentials or real user records were executed or accessed. The public source HTML was rendered in a fresh temporary browser profile.",
            "capture_checklist": [
                {"role":"Customer","screen":"Customer dashboard/order form/order history/QR access","status":"Pending capture in a consented test environment","use_only":"Synthetic test customers, contact details, orders and QR identities"},
                {"role":"Staff","screen":"Order processing/verification/station inventory","status":"Pending capture in a consented test environment","use_only":"Synthetic orders, staff identities and inventory scenarios"},
                {"role":"Rider","screen":"Assigned deliveries/navigation/delivery completion","status":"Pending capture in a consented test environment","use_only":"Synthetic rider identities, addresses and deliveries"},
                {"role":"Administrator","screen":"Dashboard/sales report/user management/activity logs","status":"Pending capture in a consented test environment","use_only":"Synthetic user identities, sales, account records and logs"},
            ],
        }
        (ASSETS / "previews_manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
        print(json.dumps({"previews":[p["file"] for p in previews],"manifest":"assets/previews_manifest.json"}))
    finally:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=5)
        # Delete only the disposable profile inside this helper's source directory.
        if profile.exists():
            resolved = profile.resolve()
            assert resolved.parent == SOURCE.resolve() and resolved.name == ".capture-profile"
            for attempt in range(10):
                try:
                    shutil.rmtree(resolved)
                    break
                except PermissionError:
                    time.sleep(.5)


if __name__ == "__main__":
    main()
