# MailApp

A lightweight, self-hosted webmail client that puts all your inboxes in one place: Gmail, iCloud, Yahoo, Outlook/Hotmail, and any IMAP mailbox on your own domain.
Each account has its own **Inbox** and **Spam** folder.

Written in plain PHP (no `imap` extension, no Composer), so it runs on cheap shared hosting as well as on your own machine.

## Features

- All your accounts side by side, each with its Inbox and Spam (the spam folder is detected automatically: Spam, Junk, Bulk…)
- Unread counters
- Read messages (plain text and HTML), attachments, inline images
- Remote images (trackers) are blocked until you click "Show"
- Delete (moves to Trash), Archive, Star, Mark unread, and "Not spam" in the Spam folder
- Multi-select: click the avatar (or checkbox), Shift+click for a range, or the top checkbox for everything; then apply Read, Unread, Star, Archive or Delete to all of them
- "Mark all as read" for the whole folder
- **Compose** (`c`), **Reply** (`r`), **Reply all** (`a`), **Forward** (`f`), with attachments (20 MB max). `⌘/Ctrl + Enter` to send. A copy is saved to "Sent"
- Keyboard shortcuts: `j`/`k` next/previous, `x` select, `e` archive, `#` delete, `s` star, `u` unread, `Esc` clear selection
- Light / dark / auto theme
- Desktop notifications for new mail
- Works on mobile
- Add or remove accounts from the UI; passwords added this way are stored encrypted
- Protected by a single app password (hashed, with brute-force lockout)

## Requirements

- PHP 8.2 or newer (with `openssl`)
- On macOS: `brew install php`

## 1. Configure your accounts

```bash
cd MailApp
cp config.example.php config.php
```

Open `config.php` and enter the email address and password for each account.
`config.php` contains your passwords: never commit or share it (it is already in `.gitignore`).

To show your name on outgoing mail, add `'from_name' => 'Your Name',` at the top of the array in `config.php`.

**App passwords** (regular account passwords won't work):

| Account | Where to create it |
|---|---|
| Gmail | https://myaccount.google.com/apppasswords (2-Step Verification must be on) |
| iCloud | https://account.apple.com → Sign-In and Security → App-Specific Passwords |
| Yahoo | https://login.yahoo.com/account/security → Generate app password |
| Your own domain | The mailbox password from your hosting provider |
| Hotmail / Outlook | No password: uses Microsoft sign-in (see below) |

For Gmail, IMAP must be enabled: Gmail → Settings → Forwarding and POP/IMAP.

You can also skip `config.php` entirely and add accounts from the app (the **+** button in the sidebar).

## 2. Run it locally

```bash
cd MailApp
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public
```

Open http://localhost:8000 (use `localhost`, not `127.0.0.1`, because the Microsoft sign-in requires it).

The first time, you'll be asked to create the **MailApp password** (10+ characters). It protects access to all your mail.

- Only a hash is stored, in `data/auth.json`
- 5 failed attempts lock the IP for 15 minutes
- Sessions last 14 days; the lock button in the sidebar signs you out
- The first password can only be created from the computer itself, never over the internet
- To reset it: delete `data/auth.json`, open the app and create a new one

If an account has a problem, a `!` appears next to the folder; hover over it to see the error.

## Hotmail / Outlook (Microsoft sign-in)

Microsoft no longer accepts passwords over IMAP, so you need a small app registration in your Microsoft account (one time, ~5 minutes):

1. Go to https://entra.microsoft.com → **App registrations** → **New registration**.
   If your personal account isn't allowed, create a free Azure account (https://azure.microsoft.com/free). A card may be requested for verification only.
2. **Name**: `MailApp`. **Supported account types**: *Personal Microsoft accounts only*.
3. **Redirect URI**: choose *Public client/native (mobile & desktop)* and enter `http://localhost:8000/oauth.php`
4. Click **Register** and copy the **Application (client) ID**.
5. In `config.php`, put that ID in the Hotmail account: `'client_id' => '...'` (or paste it when adding Outlook from the app).
6. In the Azure app → **Authentication** → *Allow public client flows* → **Yes** → Save.
7. Open MailApp, pick Hotmail and click **Sign in with Microsoft**.

The sign-in is cached in `data/`, so you won't have to reconnect every time.
When you deploy online, add a second redirect URI: `https://your-domain.com/mailapp/oauth.php`.

## Adding accounts from the app

Click **+** in the sidebar (or the accounts menu → *Add account*) and choose Gmail, Outlook / Hotmail, Yahoo, iCloud, WhatsApp, or *Other (IMAP)* for any other provider.
The app tests the connection before saving. Accounts added this way are stored in `data/accounts.json`, with passwords encrypted (key in `data/.key`).

To remove an account: accounts menu → `×`, or right-click the account in the sidebar. No emails are deleted. Accounts defined in `config.php` stay there.

WhatsApp has no IMAP, so it is added as a shortcut that opens WhatsApp Web in its own window.

## Deploying to shared hosting (Apache, e.g. DreamHost)

Only the `public/` folder should be reachable from the web. The code, `config.php` and `data/` (encrypted passwords, tokens) live **outside** the web root so nobody can download them.

```
~/mailapp-private/        ← src/, config.php, data/   (not web-accessible)
~/example.com/mailapp/    ← contents of public/       (https://example.com/mailapp)
```

1. In your hosting panel: PHP 8.2+ and **HTTPS** (e.g. Let's Encrypt) enabled for your domain.
2. Make sure your user has SSH/SFTP access.
3. Create the MailApp password and add your accounts locally first, so `data/` is ready.
4. Upload the files (replace `USER` and `example.com`):

```bash
cd MailApp
rsync -av --exclude public --exclude .DS_Store --exclude data/sessions --exclude data/attempts.json ./ USER@example.com:mailapp-private/
rsync -av --exclude .DS_Store public/ USER@example.com:example.com/mailapp/
ssh USER@example.com 'chmod 700 mailapp-private mailapp-private/data'
```

5. Hotmail: in the Azure app → **Authentication**, add the redirect URI `https://example.com/mailapp/oauth.php`.
6. Open https://example.com/mailapp and sign in with your MailApp password.

`public/boot.php` finds the private folder automatically (`../` locally, `~/mailapp-private/` on the server). You can also set the `MAILAPP_PRIVATE` environment variable.

To update after a code change, just run the `rsync` commands again.

## Roadmap

- Search

## Project structure

```
config.example.php   account template
src/Imap.php         IMAP client (pure PHP)
src/Smtp.php         sending mail (SMTP, pure PHP)
src/Mime.php         email parsing (encodings, attachments)
src/App.php          app logic / API
src/OAuth.php        Microsoft sign-in (Hotmail/Outlook)
src/Store.php        accounts added from the app (encrypted passwords)
src/Auth.php         MailApp password (login, lockout)
data/                accounts, key and tokens (created automatically, never share)
public/              the web interface
```
