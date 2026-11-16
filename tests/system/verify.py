"""Real cookie/CSRF HTTP acceptance and 10-client performance on disposable fixtures."""
import concurrent.futures, http.cookiejar, html, json, math, os, pathlib, platform, re, time
import urllib.error, urllib.parse, urllib.request

root = pathlib.Path(os.environ['CTE_SYSTEM_DIRECTORY']).resolve()
if not root.name.startswith('cte-system-') or root.parent not in (pathlib.Path('/private/tmp'), pathlib.Path('/tmp')):
    raise RuntimeError('Disposable private test directory required')
settings = json.loads((root / 'runtime.json').read_text())
fixture = json.loads((root / 'fixture.json').read_text())
assert fixture['people'] >= 10000 and fixture['records'] >= 100000

class Client:
    def __init__(self):
        self.version = None
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
    def request(self, path, method='GET', data=None, inertia=True, csrf=True):
        headers = {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        if inertia and method == 'GET':
            headers['X-Inertia'] = 'true'
            headers['X-Inertia-Version'] = self.version or ''
        if csrf:
            for cookie in self.cookies:
                if cookie.name == 'XSRF-TOKEN': headers['X-XSRF-TOKEN'] = urllib.parse.unquote(cookie.value)
        if data is not None:
            data = json.dumps(data).encode(); headers['Content-Type'] = 'application/json'
        started = time.perf_counter()
        try: response = self.opener.open(urllib.request.Request(settings['url']+path, data=data, headers=headers, method=method),timeout=60)
        except urllib.error.HTTPError as error: response = error
        body = response.read()
        return response.code, body, dict(response.headers), (time.perf_counter()-started)*1000
    def login(self, user):
        response = self.request('/giris',inertia=False)
        assert response[0] == 200
        page = json.loads(html.unescape(re.search(r'data-page="([^"]+)"',response[1].decode()).group(1)))
        self.version = page['version']
        response = self.request('/giris','POST',{'username':f'synthetic{user}','password':settings['password']},inertia=False)
        assert response[0] == 200, (user,response[0])

clients=[Client() for _ in range(10)]
for i,client in enumerate(clients,1): client.login(i)
passed=[]
def check(condition,name):
    if not condition: raise AssertionError(name)
    passed.append(name)
admin=clients[0]
routes=['/portal?per_page=50','/ilk-gorus?per_page=50','/infaz?per_page=50','/izin?per_page=50','/egitim?per_page=50','/revir?per_page=50','/mesem?per_page=50','/psikososyal?per_page=50','/moduller/synthetic-performance?per_page=50','/moduller/synthetic-performance?per_page=50&filter_field='+str(fixture['fields'][1])+'&filter_op=gte&filter_value=99900','/moduller/synthetic-performance?per_page=50&sort_field='+str(fixture['fields'][1])+'&sort_direction=desc','/personel-kadrosu']
metrics=[]
def measured(client,path):
    code,body,headers,elapsed=client.request(path)
    check(code==200,'http_'+path.split('?')[0])
    data=json.loads(body)
    if 'rows' in data['props']: check(len(data['props']['rows'])<=50,'bounded_'+path.split('?')[0])
    return {'elapsed_ms':elapsed,'bytes':len(body),'queries':int(headers.get('X-System-Queries',0)),'query_ms':float(headers.get('X-System-Query-Ms',0))}
for route in routes:
    cold=measured(admin,route)
    with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool:
        samples=list(pool.map(lambda pair: measured(*pair),[(clients[i%10],route) for i in range(20)]))
    times=sorted(row['elapsed_ms'] for row in samples)
    metrics.append({'route':route,'first_request_ms':round(cold['elapsed_ms'],2),'samples':20,'concurrent_clients':10,'p95_ms':round(times[math.ceil(len(times)*.95)-1],2),'max_bytes':max(s['bytes'] for s in samples),'max_queries':max(s['queries'] for s in samples),'max_query_ms':max(s['query_ms'] for s in samples)})
(root/'performance-progress.json').write_text(json.dumps(metrics,ensure_ascii=False,indent=2))
# All remaining page families use actual authentication, middleware, DB and serializers.
for route in ['/kurslar','/ziyaret','/manevi','/hijyen-isg','/calisma','/kurum/telefon','/kurum/duyurular','/kurum/kurul','/kurum/mudurnobet','/kurum/vardiyanobet','/kurum/infaznobet','/kurum/kismiarama','/kurum/yemek','/kurum/sohbet','/sayim','/uyap-karsilastirma','/istatistik','/mesem-istatistik','/ozel-rapor','/belgeler/kimlik','/belgeler/fotograf','/belgeler/teblig','/belgeler/bakanlik_sevk','/mesajlar','/excel-birlestir','/koguslar','/toplu-durum']:
    check(admin.request(route)[0]==200,'family_'+route)
# Writes cross actual CSRF and cookie boundaries; no testing-environment CSRF bypass.
check(admin.request('/mesem/kisiler/1/hizli','POST',{'action':'basvuru','revision':0},csrf=False)[0]==419,'csrf_required')
mesem_props=json.loads(admin.request('/mesem/kisiler/1')[1])['props']
mesem_revision=mesem_props['revision']
refusal=next(x['id'] for x in mesem_props['options']['mesem_durumu'] if x['semantic']=='İSTEMİYOR')
first_action='basvuru' if mesem_props['values'].get('mesem_durumu')==refusal else 'istemiyor'
second_action='istemiyor' if first_action=='basvuru' else 'basvuru'
check(admin.request('/mesem/kisiler/1/hizli','POST',{'action':first_action,'revision':mesem_revision})[0]==200,'mesem_cookie_write')
check(admin.request('/mesem/kisiler/1/hizli','POST',{'action':second_action,'revision':mesem_revision})[0]==409,'mesem_stale_conflict')
interview_revision=json.loads(admin.request('/ilk-gorus/kisiler/1')[1])['props']['revision']
check(admin.request('/ilk-gorus/kisiler/1','PUT',{'revision':interview_revision,'values':{'huk_meslek':'SENTETİK HTTP'}})[0]==200,'interview_write')
check(admin.request('/ilk-gorus?per_page=50&q=00000000001')[0]==200,'interview_filter_after_save')
# The following properties are not timing thresholds; they fail independently.
reader=clients[1]
check(reader.request('/ozel-rapor')[0]==403,'admin_report_denied')
check(reader.request('/yonetim/kullanicilar')[0]==403,'admin_management_denied')
check(reader.request('/revir/kisiler/1','PUT',{'revision':0,'values':{'egt_okulsifre':'FORGED'}})[0]==422,'foreign_field_denied')
check(admin.request('/birimler/servisler/ilkgorus.php')[0]==200,'legacy_get_owned_redirect')
check(admin.request('/birimler/servisler/ilkgorus.php','POST',{})[0]==405,'legacy_post_not_redirected')
check(admin.request('/ilk-gorus/cikti?columns[0]=egt_okulsifre')[0]==422,'hidden_export_field_denied')
# Module lifecycle crosses real designer, publication, runtime and CSV routes.
code='synthetic-http-'+os.urandom(6).hex()
response=admin.request('/yonetim/moduller','POST',{'code':code,'name':'SENTETİK HTTP MODÜLÜ','record_mode':'standalone'})
check(response[0]==201,'module_create')
state=json.loads(response[1]); module=state['module']['id']; version=state['version']
field={'id':None,'type':'text','label':'Sentetik başlık','helper':'','required':True,'show_in_form':True,'show_in_table':True}
response=admin.request(f'/yonetim/moduller/{module}/taslak','PUT',{'revision':state['module']['revision'],'version_id':version['id'],'version_revision':version['revision'],'name':'SENTETİK HTTP MODÜLÜ','fields':[field]})
check(response[0]==200,'module_draft_save')
state=json.loads(response[1]); field_id=str(state['version']['definition']['fields'][0]['id'])
publication=json.loads(admin.request(f'/yonetim/moduller/{module}')[1])['props']['publication']
check(admin.request(f'/yonetim/moduller/{module}/yayinla','POST',dict(publication,confirmed=True))[0]==200,'module_publish')
check(reader.request('/moduller/'+code)[0]==403,'module_no_implicit_staff_grant')
response=admin.request('/moduller/'+code+'/kayitlar','POST',{'version_id':state['version']['id'],'values':{field_id:'SENTETİK UÇTAN UCA'}})
check(response[0]==201,'module_record_create')
rows=json.loads(admin.request('/moduller/'+code)[1])['props']['rows']
check(len(rows)==1 and rows[0]['values'][field_id]=='SENTETİK UÇTAN UCA','module_record_read_back')
check(admin.request('/moduller/'+code+'/cikti',inertia=False)[0]==200,'module_csv_export')
output={'fixture':fixture,'machine':{'system':platform.system(),'release':platform.release(),'cpu':platform.machine(),'logical_cpus':os.cpu_count()},'transport':'loopback HTTP, file sessions, private MariaDB socket, 10 PHP workers','first_request_definition':'first route request in an already started runtime; not a cold database buffer','metrics':metrics,'checks':len(passed),'status':'passed' if all(m['p95_ms']<=500 for m in metrics) else 'threshold_failed','data_target_ms':500,'threshold_failures':[m['route'] for m in metrics if m['p95_ms']>500]}
(root/'http-result.json').write_text(json.dumps(output,ensure_ascii=False,indent=2))
print(json.dumps({'checks':len(passed),'metrics':metrics,'threshold_failures':output['threshold_failures']},ensure_ascii=False))

if output['threshold_failures']:
    raise SystemExit('Performance threshold exceeded: '+', '.join(output['threshold_failures']))
