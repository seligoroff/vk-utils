#!/bin/bash
set -e
cd /home/seligoroff/projects/vk-insight

D=1.0
R=storage/app/reports
F=$R/likers-core-teatr-subbota-v3.json

echo "=== 37678 ===" && php artisan vk:likers-core --owner=-670335 --post=37678 --k=2 --demographics --delay=$D --format=json 2>/dev/null > /tmp/j1.json && echo "OK $(wc -c < /tmp/j1.json)B"
echo "=== 37732 ===" && php artisan vk:likers-core --owner=-670335 --post=37732 --k=2 --demographics --delay=$D --format=json 2>/dev/null > /tmp/j2.json && echo "OK $(wc -c < /tmp/j2.json)B"
echo "=== 37665 ===" && php artisan vk:likers-core --owner=-670335 --post=37665 --k=2 --demographics --delay=$D --format=json 2>/dev/null > /tmp/j3.json && echo "OK $(wc -c < /tmp/j3.json)B"

echo '{"37678":' > $F && cat /tmp/j1.json >> $F && echo ',"37732":' >> $F && cat /tmp/j2.json >> $F && echo ',"37665":' >> $F && cat /tmp/j3.json >> $F && echo '}' >> $F
echo "ГОТОВО: $(wc -c < $F) байт"
rm /tmp/j1.json /tmp/j2.json /tmp/j3.json