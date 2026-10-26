# Release material (not part of the plugin ZIP)

- `wordpress-org-assets/` – files for the `assets/` folder of the WordPress.org SVN repository:
  `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`,
  and `screenshot-1.png` … `screenshot-5.png` (captions in `tablerefresh/readme.txt`, section Screenshots).
  Original artwork; no TablePress or Google logos. Screenshots: clean WordPress 7.1.3 + TablePress 3.4.1,
  Google's public "Class Data" sample sheet (fictional data).
- Build the plugin ZIP with `sh dev/build-zip.sh <commit> [suffix]` (output in `dist/`, not committed).

Submission to WordPress.org needs the explicit approval of the project owner, and the `Contributors`
field in `readme.txt` must then name the owner's WordPress.org user name(s).
