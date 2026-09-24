# Releasing

The lite build is distributed through the **WordPress.org plugin directory**.
Publishing there is the deploy: WordPress checks the directory centrally, so
every site is offered the new version on the Plugins screen without the plugin
having to check for itself. There is no update checker in this build and no
credential shipped with it.

Repository: <https://github.com/MME-pro/mme-mail-to-smtp-lite>

---

## Everyday work (no release)

Changes that are not ready to go out to sites just go to `main`. Nothing is
published to the directory, so no site sees anything.

```bash
git add -A
git commit -m "Short summary of the change"
git push origin main
```

---

## Cutting a release

### 1. Bump the version in all four places

They must agree. `Stable tag` in `readme.txt` is what the directory serves, and
the `Version:` header is what an installed site compares against it - a mismatch
either offers every site an update it already has or hides one it needs.

| File | Line |
|---|---|
| `modern-mailer-oauth.php` | ` * Version:           0.4.3` |
| `modern-mailer-oauth.php` | `const VERSION     = '0.4.3';` |
| `package.json` | `"version": "0.4.3",` |
| `readme.txt` | `Stable tag: 0.4.3` |

All four at once, replacing the old version with the new one:

```bash
OLD=0.4.2
NEW=0.4.3

sed -i "s/Version:           $OLD/Version:           $NEW/" modern-mailer-oauth.php
sed -i "s/const VERSION     = '$OLD'/const VERSION     = '$NEW'/" modern-mailer-oauth.php
sed -i "s/\"version\": \"$OLD\"/\"version\": \"$NEW\"/" package.json
sed -i "s/^Stable tag: $OLD/Stable tag: $NEW/" readme.txt

grep -n "$NEW" modern-mailer-oauth.php package.json readme.txt   # expect 4 lines
```

Pick the number the way the change deserves: `0.4.2 -> 0.4.3` for fixes and
small additions, `0.4.2 -> 0.5.0` for a new provider or a reworked screen.
Never reuse or lower a number — WordPress compares versions, so a site on a
higher number will never be offered a lower one.

### 2. Add a changelog entry

At the top of the `== Changelog ==` section in `readme.txt`, above the previous
version. This text is what an admin reads in the update details:

```
= 0.4.3 =
* What changed, in a sentence a site owner would understand.
```

### 3. Build the admin app

`build/` is committed, and the directory serves exactly what is committed, so a
stale `build/` ships a stale admin app:

```bash
npm ci
npm run build
```

### 4. Run the tests

```bash
cd tests && ./run.sh
```

On Windows LocalWP, see the invocation in [../README.md](../README.md#tests) —
the CLI binary loads no extensions by default.

### 5. Commit and push

```bash
git add -A
git commit -m "Release 0.4.3 - short summary"
git push origin main
```

A tag is optional here and triggers nothing; it is only a marker of what was
submitted.

### 6. Publish to the directory

Copy the plugin into the `trunk/` of the plugin's SVN checkout, tag it, and
commit. `Stable tag` in `readme.txt` is what decides which tag the directory
actually serves, so trunk alone changes nothing that sites can see.

Exclude everything that is not part of the shipped plugin: `node_modules/`,
`tests/`, `tools/`, `broker/`, `src/`, `docs/`, `.github/`, `package.json`,
`package-lock.json`, `postcss.config.js`. `build/` **is** shipped.

The directory picks the change up within minutes, and sites are offered it on
their next update check.

---

## What a site does next

Nothing is pushed to a site. WordPress asks the directory on its own:

- The check runs on cron, roughly twice a day, so a site notices a new version
  within about half a day.
- To see it immediately on a given site: **Dashboard - Updates - Check again**.
- A deactivated copy is still offered updates, because core does the check
  rather than the plugin.
- Sites with auto-updates enabled for this plugin update themselves on the next
  cron run. Everyone else clicks **Update now** on the Plugins screen.

---

## When something goes wrong

**The directory is still serving the old version.** `Stable tag` in `readme.txt`
does not point at the tag you committed. Fix it in both trunk and the tag.

**A release went out broken.** Do not reuse the number — sites that already
updated would be stranded on it. Fix forward: bump to the next version and
publish again.

---

## Quick reference

```bash
# ordinary change
git add -A && git commit -m "..." && git push origin main

# release 0.4.3
# (bump the 4 version lines + changelog, npm run build, run tests first)
git add -A && git commit -m "Release 0.4.3 - ..." && git push origin main
# then copy to SVN trunk, tag 0.4.3, set Stable tag, svn commit
```
