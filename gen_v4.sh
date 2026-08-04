#!/bin/bash
set -e
cd /home/seligoroff/projects/vk-insight
D=1.0
R=storage/app/reports

echo "=== 37678 ===" && php artisan vk:likers-core --owner=-670335 --post=37678 --k=2 --demographics --delay=$D --output=/tmp/j1.json 2>/dev/null && echo "OK $(wc -c < /tmp/j1.json)B"
echo "=== 37732 ===" && php artisan vk:likers-core --owner=-670335 --post=37732 --k=2 --demographics --delay=$D --output=/tmp/j2.json 2>/dev/null && echo "OK $(wc -c < /tmp/j2.json)B"
echo "=== 37665 ===" && php artisan vk:likers-core --owner=-670335 --post=37665 --k=2 --demographics --delay=$D --output=/tmp/j3.json 2>/dev/null && echo "OK $(wc -c < /tmp/j3.json)B"

python3 -c "
import json
d = {}
for pid, fn in [('37678','/tmp/j1.json'),('37732','/tmp/j2.json'),('37665','/tmp/j3.json')]:
    d[pid] = json.load(open(fn))
json.dump(d, open('$R/likers-core-teatr-subbota-v4.json','w'), ensure_ascii=False, indent=2)
print('v4.json:', len(json.dumps(d)), 'bytes')
"
rm /tmp/j1.json /tmp/j2.json /tmp/j3.json