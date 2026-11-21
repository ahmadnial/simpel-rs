#!/bin/sh
# Build in a staging directory; publish the manifest only after all assets exist.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
version=$(cat .node-version)
case $(uname -m) in x86_64) arch=x64 ;; aarch64|arm64) arch=arm64 ;; *) arch=unsupported ;; esac
runtime="$HOME/.local/share/simpel-rs/node-v${version}-linux-${arch}/bin"
if [ "$(uname -s)" = Linux ] && [ -x "$runtime/node" ]; then
    PATH="$runtime:$PATH"
    export PATH
fi
node -e 'if (Number(process.versions.node.split(".")[0]) !== 24) { console.error("Build SIMPEL memerlukan Node 24. Gunakan runtime pada .node-version."); process.exit(1); }'
node --version
npm ci --ignore-scripts
stage=$(mktemp -d "$PWD/public/.frontend-build.XXXXXX")
trap 'rm -rf "$stage"' EXIT HUP INT TERM
npm run build -- --outDir "$stage" --emptyOutDir
node --input-type=module - "$stage" <<'JS'
import fs from 'node:fs';
import path from 'node:path';
const stage = process.argv[2];
const manifest = JSON.parse(fs.readFileSync(path.join(stage, 'manifest.json')));
if (!manifest['resources/js/app.js']) throw new Error('Missing app entry');
for (const entry of Object.values(manifest)) {
    for (const file of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
        if (!file.startsWith('assets/') || !fs.existsSync(path.join(stage, file))) throw new Error('Missing build asset');
    }
}
JS
mkdir -p public/build/assets
cp -R "$stage/assets/." public/build/assets/
cp "$stage/manifest.json" public/build/manifest.json.new
mv public/build/manifest.json.new public/build/manifest.json
printf '%s\n' 'Build dipasang; aset hash lama dipertahankan untuk tab pengguna yang masih terbuka.'
