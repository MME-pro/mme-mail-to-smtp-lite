---
name: deploy
description: Release the free plugin to wordpress.org - bump the version, add the changelog, build, commit and push to git, then put the code in SVN trunk and a new tag and commit it. Use when the user says deploy, release, or "trunk aur tag mein daal do".
argument-hint: "[version, e.g. 1.0.2 - default: next patch]"
---

# Deploy to wordpress.org

The user has authorised this whole flow, git push and SVN commit included,
whenever they invoke it. Do not ask again for permission to commit. Stop and
report if any step fails - never commit past a failure.

The process is [docs/RELEASING.md](../../../docs/RELEASING.md). The SVN checkout
is `D:\Talha\MME Mail to SMTP` (`trunk/`, `tags/`, `assets/`).

## Steps

1. **Version.** Use `$ARGUMENTS` if given, otherwise the next patch number
   after the `Version:` in `mme-mail-to-smtp.php`. It must be higher than every
   folder in `D:\Talha\MME Mail to SMTP\tags`.

2. **Bump all four lines** (plugin header `Version:`, `const VERSION`,
   `package.json` `"version"`, `readme.txt` `Stable tag:`) with the sed block
   in RELEASING.md step 1. Confirm with grep that all four agree.

3. **Changelog.** Add `= <version> =` at the top of `== Changelog ==` in
   `readme.txt`. Write it from `git log` since the last release commit, in
   sentences a site owner would understand, matching the existing entries.

4. **Build** if anything under `src/` changed since the last release:
   `npm run build`. `build/` is committed and is what ships.

5. **Tests.** Run `tests/run.sh` (Windows LocalWP invocation in README.md
   § Tests). If they fail, stop. If the environment cannot run them, say so and
   ask before continuing.

6. **Git.** `git add -A`, commit `Release <version> - <short summary>`, push
   `origin main`.

7. **SVN.** `bash tools/svn-deploy.sh <version>` - it checks the four version
   lines, mirrors the tracked files into `trunk/`, copies `trunk/` to
   `tags/<version>/`, adds and removes files in svn, and commits. Only
   committed files ship, which is why git comes first.

8. **Report** the version, the git commit, and the SVN revision. The
   directory serves it within minutes; sites see it on their next update
   check.
