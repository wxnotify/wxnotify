#!/bin/bash
set -euo pipefail

for i in a d c; do
   echo "Deploying app to server ${i}.."
   ssh "wxnadmin@server-${i}" "/usr/local/bin/deploy.sh"
done
