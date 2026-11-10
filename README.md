# MailApp

**All your email accounts in one simple app.**

MailApp lets you read and send email from Gmail, iCloud, Yahoo, Outlook/Hotmail and any other email address, all in one window. It runs on your own computer (or your own website), so your emails and passwords never go through anyone else's server.

It's free and open source. Anyone can use it.

---

## What can it do?

- See all your email accounts in one place, each with its **Inbox** and **Spam**
- Read emails, open attachments, and see pictures
- Write new emails, reply and forward, with attachments
- Delete, archive, star, or mark emails as read/unread, one by one or many at once
- Block hidden tracking images until you choose to show them
- Light mode and dark mode
- Get a notification on your computer when new mail arrives
- Works on your phone too
- Protected by your own password, so nobody else can open it

---

## Before you start

You need **PHP**, a small free program that runs MailApp. You install it once.

**On a Mac:**

1. Open the **Terminal** app (press `⌘ + Space`, type *Terminal*, press Enter).
2. If you don't have Homebrew yet, install it by following the one-line instruction on https://brew.sh
3. Then type this and press Enter:

   ```bash
   brew install php
   ```

**On Windows:** download PHP from https://windows.php.net/download (choose the latest "Thread Safe" zip), unzip it to `C:\php`, and add `C:\php` to your PATH. Or use a ready-made package like [XAMPP](https://www.apachefriends.org).

**On Linux:** `sudo apt install php` (Ubuntu/Debian).

---

## Step 1 — Download MailApp

Click the green **Code** button at the top of this page → **Download ZIP**, then unzip it.
(Or, if you use git: `git clone https://github.com/aminebenemhamed/MailApp.git`)

## Step 2 — Start MailApp

Open the Terminal, go into the MailApp folder, and start it:

```bash
cd path/to/MailApp
php -S localhost:8000 -t public
```

> Tip: on a Mac you can type `cd ` (with a space) and then drag the MailApp folder into the Terminal window to fill in the path.

Leave this window open while you use MailApp. To stop it, press `Ctrl + C`.

## Step 3 — Open it in your browser

Go to **http://localhost:8000**

The first time, MailApp asks you to **create a password** (at least 10 characters). You'll use it every time you open MailApp. Choose something you'll remember.

## Step 4 — Add your email accounts

Click the **+** button on the left side and choose your provider (Gmail, Outlook/Hotmail, Yahoo, iCloud, or *Other*).
MailApp checks that everything works before saving the account.

That's it! 🎉

---

## About "app passwords"

For security, Gmail, iCloud and Yahoo **don't accept your normal password** in apps like this. Instead, you create a special **app password** (a code of 16 letters) just for MailApp. It takes one minute:

| Email | Where to create the app password |
|---|---|
| **Gmail** | https://myaccount.google.com/apppasswords — 2-Step Verification must be turned on first. Also turn on IMAP: Gmail → Settings → *Forwarding and POP/IMAP*. |
| **iCloud** | https://account.apple.com → *Sign-In and Security* → *App-Specific Passwords* |
| **Yahoo** | https://login.yahoo.com/account/security → *Generate app password* |
| **Your own domain** | Use the normal password of your mailbox. Your host gives you the "IMAP server" (for example `imap.yourhost.com`). |
| **Outlook / Hotmail** | No password needed — see below. |

Copy the code, paste it in MailApp instead of your normal password, and you're done.

## Outlook / Hotmail

Microsoft requires you to sign in through its own page. To allow that, you register MailApp once with Microsoft (about 5 minutes, free):

1. Go to https://entra.microsoft.com → **App registrations** → **New registration**.
   (If it says your account isn't allowed, create a free Azure account at https://azure.microsoft.com/free. It may ask for a card only to verify you; you won't be charged.)
2. **Name:** `MailApp`
   **Supported account types:** *Personal Microsoft accounts only*
3. **Redirect URI:** choose *Public client/native (mobile & desktop)* and enter `http://localhost:8000/oauth.php`
4. Click **Register**, then copy the **Application (client) ID** (a long code like `1a2b3c4d-...`).
5. On the left, go to **Authentication** → set *Allow public client flows* to **Yes** → **Save**.
6. In MailApp, add an Outlook/Hotmail account, paste the ID, and click **Sign in with Microsoft**.

You only do this once. MailApp remembers the sign-in.

---

## Your privacy and security

- Everything stays on **your** computer, in the `data/` folder.
- Passwords you add in the app are **encrypted**.
- Your MailApp password is never stored as-is (only a secure "hash").
- After 5 wrong password attempts, MailApp locks for 15 minutes.
- Forgot your MailApp password? Delete the file `data/auth.json` and open MailApp again to create a new one.
- **Never share** the `data/` folder or a `config.php` file — they contain your accounts.

---

## Keyboard shortcuts

| Key | Action |
|---|---|
| `c` | New email |
| `r` / `a` / `f` | Reply / Reply all / Forward |
| `j` / `k` | Next / previous email |
| `x` | Select |
| `e` | Archive |
| `#` | Delete |
| `s` | Star |
| `u` | Mark unread |
| `Esc` | Clear selection |
| `⌘/Ctrl + Enter` | Send |

---

## For advanced users

<details>
<summary><b>Set up accounts in a config file instead of the app</b></summary>

```bash
cp config.example.php config.php
```

Edit `config.php` with your addresses and app passwords. To show your name on outgoing mail, add `'from_name' => 'Your Name',` at the top of the array. `config.php` is ignored by git.
</details>

<details>
<summary><b>Put MailApp online on your own website (shared hosting, Apache)</b></summary>

Only the `public/` folder should be visible on the web. The code, `config.php` and `data/` must stay **outside** your website folder.

```
~/mailapp-private/        ← everything except public/   (not visible on the web)
~/example.com/mailapp/    ← the contents of public/      (https://example.com/mailapp)
```

1. In your hosting panel: enable PHP 8.2+ and HTTPS for your domain, and SSH access for your user.
2. Create your MailApp password and add your accounts locally first, so `data/` is ready.
3. Upload (replace `USER` and `example.com`):

```bash
rsync -av --exclude public --exclude .DS_Store --exclude data/sessions --exclude data/attempts.json ./ USER@example.com:mailapp-private/
rsync -av --exclude .DS_Store public/ USER@example.com:example.com/mailapp/
ssh USER@example.com 'chmod 700 mailapp-private mailapp-private/data'
```

4. For Outlook/Hotmail, add the redirect URI `https://example.com/mailapp/oauth.php` in your Azure app.
5. Open https://example.com/mailapp.

MailApp finds the private folder automatically. You can also set the `MAILAPP_PRIVATE` environment variable.
</details>

<details>
<summary><b>How it's built</b></summary>

Plain PHP 8.2+, no extensions beyond `openssl`, no Composer, no database.

```
config.example.php   account template
src/Imap.php         IMAP client (pure PHP)
src/Smtp.php         sending mail (SMTP)
src/Mime.php         email parsing (encodings, attachments)
src/App.php          app logic / API
src/OAuth.php        Microsoft sign-in
src/Store.php        accounts added from the app (encrypted)
src/Auth.php         MailApp password and lockout
public/              the web interface
data/                created automatically — your private data
```
</details>

---

## Coming soon

- Search

## Questions or problems?

Open an [issue](https://github.com/aminebenemhamed/MailApp/issues) on GitHub.
