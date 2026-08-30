#!/usr/bin/env bash
set -euo pipefail

container="${OPENBAO_CONTAINER:-simpel-tte-openbao}"

for share in 1 2 3; do
    read -r -s -p "Masukkan OpenBao unseal key ke-${share}: " unseal_key
    printf '\n'
    docker exec -i "$container" env BAO_ADDR=http://127.0.0.1:8200 bao operator unseal "$unseal_key" >/dev/null
    unset unseal_key
done

status="$(docker exec "$container" env BAO_ADDR=http://127.0.0.1:8200 bao status -format=json)"
if printf '%s' "$status" | grep -q '"sealed":false'; then
    printf 'OpenBao berhasil di-unseal.\n'
    exit 0
fi

printf 'OpenBao masih sealed; periksa apakah tiga share yang dimasukkan benar.\n' >&2
exit 1
