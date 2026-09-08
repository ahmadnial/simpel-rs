#!/usr/bin/env bash
set -euo pipefail

container="${OPENBAO_CONTAINER:-simpel-tte-openbao}"
bao_addr="${OPENBAO_ADDR:-http://127.0.0.1:8200}"

for share in 1 2 3; do
    read -r -s -p "Masukkan OpenBao unseal key ke-${share}: " unseal_key
    printf '\n'
    if ! printf '%s\n' "$unseal_key" | docker exec -i -e BAO_ADDR="$bao_addr" "$container" bao operator unseal; then
        unset unseal_key
        printf 'Share ke-%s ditolak oleh OpenBao; proses dihentikan tanpa mencoba share berikutnya.\n' "$share" >&2
        exit 1
    fi
    unset unseal_key
done

status="$(docker exec -e BAO_ADDR="$bao_addr" "$container" bao status -format=json)"
if printf '%s' "$status" | grep -Eq '"sealed"[[:space:]]*:[[:space:]]*false'; then
    printf 'OpenBao berhasil di-unseal.\n'
    exit 0
fi

printf 'OpenBao masih sealed; periksa apakah tiga share yang dimasukkan benar.\n' >&2
exit 1
