#!/usr/bin/env node
// Headless-Chromium driver for InternTrack's Vue SPA, piped a small script
// over stdin — same idea as chromium-cli (not available on this machine),
// hand-rolled with Playwright since the app's login is a real Sanctum
// cookie/CSRF dance that only works when a real browser runs the SPA's own
// JS (a raw curl-based smoke script can't do this — see SKILL.md).
//
// Usage:
//   node driver.mjs [--base http://localhost:5173] [--out ./screenshots] [--session name] < script.txt
//
// Script commands, one per line, blank lines and lines starting with # ignored:
//   nav <path-or-url>              Navigate (relative paths resolve against --base)
//   login <username> <password>    Fill #identifier/#password, submit, wait for redirect off /login
//   wait-for text=<text>           Wait for visible text
//   wait-for sel=<css>             Wait for a CSS selector to be visible
//   click <css-or-text=...>        Click (text=... uses getByText; :has-text() works in a css string)
//   fill <css> <text...>           Fill an input/textarea
//   press <key>                    Keyboard.press on the page (e.g. Enter)
//   screenshot [name]              Save a full-page PNG
//   url                            Print the current URL
//   console                        Print collected console errors / page errors so far
//   eval <js>                      page.evaluate(js) and print the result
//   sleep <ms>                     Explicit wait — last resort, prefer wait-for
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { createInterface } from 'node:readline';
import path from 'node:path';

const args = process.argv.slice(2);
const flag = (name, fallback) => {
  const i = args.indexOf(name);
  return i === -1 ? fallback : args[i + 1];
};

const BASE = flag('--base', 'http://localhost:5173');
const OUT = flag('--out', './screenshots');
const SESSION = flag('--session', 'default');
const outDir = path.join(OUT, SESSION);

const consoleLog = [];
let shotCount = 0;

async function main() {
  await mkdir(outDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleLog.push(`[console] ${msg.text()}`);
  });
  page.on('pageerror', (err) => consoleLog.push(`[pageerror] ${err.message}`));

  const rl = createInterface({ input: process.stdin, terminal: false });
  let exitCode = 0;

  for await (const raw of rl) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    process.stdout.write(`> ${line}\n`);
    try {
      await runCommand(page, line);
    } catch (err) {
      process.stdout.write(`ERROR: ${err.message}\n`);
      exitCode = 1;
    }
  }

  await browser.close();
  process.exit(exitCode);
}

async function runCommand(page, line) {
  const [cmd, ...rest] = line.split(' ');
  const arg = rest.join(' ');

  switch (cmd) {
    case 'nav': {
      const url = /^https?:\/\//.test(arg) ? arg : new URL(arg, BASE).toString();
      // networkidle, not load: under Vite dev, Tailwind's CSS is injected by a
      // JS module after the `load` event fires. A screenshot taken soon after
      // `load` can catch the page before backdrop-filter (the login card's
      // frosted-glass panel) ever gets composited — it silently never appears,
      // even seconds later. See SKILL.md Gotchas.
      await page.goto(url, { waitUntil: 'networkidle' });
      break;
    }
    case 'login': {
      const [username, password] = rest;
      await page.goto(new URL('/login', BASE).toString(), { waitUntil: 'networkidle' });
      await page.locator('#identifier').fill(username);
      await page.locator('#password').fill(password);
      await page.locator('button[type=submit]').click();
      // Successful login routes away from /login into /{role}/dashboard.
      await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 });
      break;
    }
    case 'wait-for': {
      if (arg.startsWith('text=')) {
        await page.getByText(arg.slice(5), { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 });
      } else if (arg.startsWith('sel=')) {
        await page.locator(arg.slice(4)).first().waitFor({ state: 'visible', timeout: 15000 });
      } else {
        throw new Error('wait-for needs text=... or sel=...');
      }
      break;
    }
    case 'click': {
      const locator = arg.startsWith('text=')
        ? page.getByText(arg.slice(5), { exact: false }).first()
        : page.locator(arg).first();
      await locator.click();
      break;
    }
    case 'fill': {
      const [selector, ...text] = rest;
      await page.locator(selector).first().fill(text.join(' '));
      break;
    }
    case 'press': {
      await page.keyboard.press(arg);
      break;
    }
    case 'screenshot': {
      shotCount += 1;
      const name = arg || `shot-${String(shotCount).padStart(2, '0')}`;
      const file = path.join(outDir, `${name}.png`);
      // Settle before capturing: `wait-for` only guarantees an element passed
      // Playwright's actionability check (present + visible), which can fire
      // before Chromium has actually composited a backdrop-filter layer (the
      // login card's frosted-glass panel). Racing that paint produces a
      // screenshot with the panel silently missing — confirmed by comparing
      // an immediate capture against one taken ~0.5s later on the same,
      // already-idle page. See SKILL.md Gotchas.
      await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
      await page.waitForTimeout(400);
      await page.screenshot({ path: file, fullPage: true });
      process.stdout.write(`saved ${file}\n`);
      break;
    }
    case 'url': {
      process.stdout.write(`${page.url()}\n`);
      break;
    }
    case 'console': {
      if (consoleLog.length === 0) process.stdout.write('(no console/page errors captured)\n');
      else process.stdout.write(consoleLog.join('\n') + '\n');
      break;
    }
    case 'eval': {
      const result = await page.evaluate(arg);
      process.stdout.write(`${JSON.stringify(result)}\n`);
      break;
    }
    case 'sleep': {
      await page.waitForTimeout(Number(arg));
      break;
    }
    default:
      throw new Error(`unknown command: ${cmd}`);
  }
}

main();
