"""Build locked, secret-free Windows offline release; never read the real .env."""
import argparse, hashlib, importlib, importlib.metadata, json, os, pathlib, shutil, subprocess, tempfile, zipfile
p=argparse.ArgumentParser()
for name in ['output','python-archive','php','composer']:p.add_argument('--'+name,required=True)
p.add_argument('--allow-dirty',action='store_true');args=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2]
repo=pathlib.Path(subprocess.check_output(['git','-c','core.fsmonitor=false','rev-parse','--show-toplevel'],cwd=root,text=True).strip())
source_prefix=root.relative_to(repo)
commit=subprocess.check_output(['git','-c','core.fsmonitor=false','rev-parse','HEAD'],cwd=repo,text=True).strip()
dirty=bool(subprocess.check_output(['git','-c','core.fsmonitor=false','status','--porcelain'],cwd=repo))
if dirty and not args.allow_dirty:raise SystemExit('Commit source before final release')
archive=pathlib.Path(args.python_archive).resolve();runtime_hash='97dae5274cc54867065e8d5a3226e48c35017ed332a0fdb0e27d5b5821961297'
if hashlib.sha256(archive.read_bytes()).hexdigest()!=runtime_hash:raise SystemExit('Official Python SHA256 mismatch')
out=pathlib.Path(args.output).resolve();out.mkdir(parents=True,exist_ok=True)
if out==root or root in out.parents:raise SystemExit('Output must be outside source')
with tempfile.TemporaryDirectory(prefix='cte-release-') as tmp:
    dst=pathlib.Path(tmp)/'portal-next';dst.mkdir()
    tracked=subprocess.check_output(['git','-c','core.fsmonitor=false','ls-files','--cached','--others','--exclude-standard','-z','--',source_prefix.as_posix()],cwd=repo).decode().split('\0')
    for name in tracked:
        if not name:continue
        rel=pathlib.Path(name).relative_to(source_prefix)
        if rel.parts[0] in ('tests','vendor','node_modules','storage') or name.endswith(('.log','.pyc')):continue
        src=repo/name;target=dst/rel
        if not src.is_file():continue
        if src.is_symlink():raise SystemExit('Source symlink rejected')
        target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src,target)
    shutil.copytree(root/'vendor',dst/'vendor')
    env=dict(os.environ,COMPOSER_DISABLE_NETWORK='1',COMPOSER_NO_INTERACTION='1')
    env['PATH']=str(pathlib.Path(args.php).resolve().parent)+os.pathsep+env.get('PATH','')
    subprocess.run([args.php,args.composer,'install','--no-dev','--no-scripts','--no-progress','--prefer-dist','--optimize-autoloader'],cwd=dst,env=env,check=True)
    installed=json.loads((dst/'vendor/composer/installed.json').read_text(encoding='utf-8'));dev={x['name'] for x in json.loads((dst/'composer.lock').read_text(encoding='utf-8'))['packages-dev']}
    if installed.get('dev') or any(x['name'] in dev for x in installed['packages']):raise SystemExit('Development dependencies included')
    if not (root/'public/build/manifest.json').is_file() or (root/'public/hot').exists():raise SystemExit('Local production build required')
    shutil.copytree(root/'public/build',dst/'public/build')
    for folder in ['bootstrap/cache','storage/app/private','storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs']:(dst/folder).mkdir(parents=True,exist_ok=True)
    for file in (dst/'bootstrap/cache').glob('*.php'):file.unlink()
    runtime=dst/'runtime/python';runtime.mkdir(parents=True)
    with zipfile.ZipFile(archive) as z:
        if any(pathlib.PurePosixPath(n).is_absolute() or '..' in pathlib.PurePosixPath(n).parts for n in z.namelist()):raise SystemExit('Archive traversal')
        z.extractall(runtime)
    site=runtime/'Lib/site-packages';site.mkdir(parents=True);deps={}
    for name in ['openpyxl','xlrd','et_xmlfile']:
        module=importlib.import_module(name);source=pathlib.Path(module.__file__).parent
        shutil.copytree(source,site/name,ignore=shutil.ignore_patterns('__pycache__','*.pyc','*.so','*.dylib'))
        distribution=importlib.metadata.distribution(name);metadata=pathlib.Path(distribution._path)
        shutil.copytree(metadata,site/metadata.name,ignore=shutil.ignore_patterns('__pycache__','*.pyc'));deps[name]=distribution.version
    (runtime/'python313._pth').write_text('python313.zip\n.\nLib/site-packages\n',encoding='utf-8')
    info={'version':'3.13.16','platform':'Windows x64','source':'https://www.python.org/downloads/release/python-31316/','archiveSha256':runtime_hash,'dependencies':deps,'windowsExecution':'pending'}
    (dst/'runtime/manifest.json').write_text(json.dumps(info,indent=2)+'\n',encoding='utf-8')
    for file in dst.rglob('*'):
        if not file.is_file():continue
        rel=file.relative_to(dst)
        windows_console = rel.as_posix() == 'vendor/symfony/console/Resources/bin/hiddeninput.exe' and file.read_bytes()[:2] == b'MZ'
        if file.is_symlink() or (file.suffix.lower() in {'.so','.dylib','.node','.exe','.dll','.pyd'} and rel.parts[0]!='runtime' and not windows_console):raise SystemExit('Foreign native binary: '+str(rel))
        if file.name in {'.env','auth.json','hot'} or file.suffix in {'.sql','.log','.sqlite'}:raise SystemExit('Private artifact: '+str(rel))
    files={file.relative_to(dst).as_posix():hashlib.sha256(file.read_bytes()).hexdigest() for file in sorted(dst.rglob('*')) if file.is_file()}
    manifest={'format':1,'sourceCommit':commit,'candidate':dirty,'platform':'Windows x64','files':files,'institutionAcceptance':'pending','php':'>=8.2','python':info}
    (dst/'release.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    final=out/('cte-portal-'+commit[:8]+('-candidate' if dirty else '')+'-windows-x64.zip')
    with zipfile.ZipFile(final,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
        for file in sorted(dst.rglob('*')):
            if file.is_file():z.write(file,'portal-next/'+file.relative_to(dst).as_posix())
            elif file.is_dir():z.write(file,'portal-next/'+file.relative_to(dst).as_posix()+'/')
    with zipfile.ZipFile(final) as z:
        for name,expected in files.items():
            if hashlib.sha256(z.read('portal-next/'+name)).hexdigest()!=expected:raise SystemExit('Package checksum mismatch')
    report={'archive':final.name,'sha256':hashlib.sha256(final.read_bytes()).hexdigest(),'sourceCommit':commit,'files':len(files),'bytes':final.stat().st_size,'candidate':dirty,'windowsExecution':'pending','institutionAcceptance':'pending'}
    final.with_suffix('.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');print(json.dumps(report))
