"""Create private settings only; never connect to an existing database."""
import argparse, base64, json, os, pathlib, secrets, tempfile, socket
parser=argparse.ArgumentParser()
parser.add_argument('--port', type=int, default=8794)
args=parser.parse_args()
if not 1024 <= args.port <= 65535: parser.error('Unprivileged port required')
parent=pathlib.Path(tempfile.gettempdir() if os.name=='nt' else '/tmp').resolve()
root=parent/('cte-system-'+secrets.token_hex(6))
root.mkdir(mode=0o700)
os.chmod(root,0o700)
for sub in ['data','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs']:
    (root/sub).mkdir(parents=True,exist_ok=True)
database_port=3306
if os.name=='nt':
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0)); database_port=listener.getsockname()[1]
settings={'host':'127.0.0.1','port':database_port if os.name=='nt' else 3306,'database':'cte','socket':'' if os.name=='nt' else str(root/'mysql.sock'),'url':f'http://127.0.0.1:{args.port}','key':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'password':secrets.token_urlsafe(24)}
path=root/'runtime.json'
fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,'w') as stream: json.dump(settings,stream)
print(root)
