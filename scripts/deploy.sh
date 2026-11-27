#!/bin/bash
for i in d c a; do
   echo "Deploying app to server ${i}.."
   ssh wxnadmin@server-${i} "/usr/local/bin/deploy.sh"
done
