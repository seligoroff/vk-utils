import json
d = json.load(open('storage/app/reports/likers-core-teatr-subbota-v4.json'))
for pid in ['37678','37665','37732']:
    data = d[pid]
    s = data['summary']
    demo = data.get('demographics', {})
    segs = {k: v['count'] for k, v in demo.items()}
    et = s.get('friend_error_types', [])
    errs = ', '.join([e['error'] + ':' + str(e['count']) for e in et])
    print(f"{pid}: likes={s['analyzed_likers']} core={s['core_users_count']} errors={s['friend_data_errors']} segs={segs} errs=[{errs}]")