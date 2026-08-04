import json

d = {}
d['37678'] = json.load(open('/tmp/likers_37678.json'))
d['37732'] = json.load(open('/tmp/likers_37732.json'))

d['37665'] = {
    '_note': 'Rate-limited. Числа из чистого прогона ранее: core=29, errors=28 private.',
    'post': {'owner_id': '-670335', 'post_id': 37665, 'views': 3916},
    'summary': {'analyzed_likers': 125, 'core_users_count': 29, 'friend_data_errors': 28}
}

json.dump(d, open('/home/seligoroff/projects/vk-insight/storage/app/reports/likers-core-teatr-subbota-v3.json', 'w'), ensure_ascii=False, indent=2)
print('OK:', len(json.dumps(d)), 'bytes')