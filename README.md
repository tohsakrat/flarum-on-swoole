安装swoole后，放在flarum根目录（和vendor文件夹平级）
启动命令
```
php flarum-swoole.php start
```
如果有fof/redis和litespeed cache插件，本身可以代替litespeed做缓存。

lsphp和swoole互斥，不为了lsphp的性能提升，没必要专门为了缓存把网关换成litespeed，毕竟litespeed网关真的难用。

觉得flarum本身很轻后端跑一两秒也没关系的懂哥别用哈，类似的思路两年前就有人搞出来了，人家没分享也是被你们喷走的。
