from playwright.sync_api import sync_playwright
B='http://tuacademia.tukanec.com/'
OUT='/home/claude/tuacademia/android/tienda/'
import sys
def login(pg, email):
    pg.goto(B+'admin.php?p=salir'); pg.goto(B+'admin.php?p=login'); pg.fill('input[name=email]', email); pg.fill('input[name=clave]', 'clave12345'); pg.click('button[type=submit]'); pg.wait_for_timeout(500)
with sync_playwright() as p:
    b=p.chromium.launch(args=['--host-resolver-rules=MAP tuacademia.tukanec.com:80 127.0.0.1:8199'])
    cx=b.new_context(viewport={'width':360,'height':640}, device_scale_factor=3, is_mobile=True, has_touch=True)
    cx.add_init_script("try{sessionStorage.setItem('tv_twa','1')}catch(e){}")
    pg=cx.new_page()
    pg.goto(B+'app.php?origen=apk')
    for n,u in enumerate(sys.argv[1:],1):
        if u.startswith('LOGIN:'):
            login(pg, u[6:]); continue
        pg.goto(B+u); pg.wait_for_timeout(1200)
        pg.screenshot(path=OUT+'borrador_%02d.png'%n)
        print(n,u,pg.title())
    b.close()
