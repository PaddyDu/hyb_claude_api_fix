# hyb_claude_api_fix
黑与白公益展的 claude 模型非常给力啊，还上了最新的 4.6
但是，可能是接口包装不太符合SSE 规范，所以使用 visual studio code 的 cline 拓展调用一致报错。
于是写了个 php脚本放到服务器上，把上传和下载数据都加工加工，标准化一下，就完美跑通了，先上效果图
<img width="1262" height="794" alt="image" src="https://github.com/user-attachments/assets/5106e5b4-bdeb-44e2-8b1d-cffb7aabe1b9" />


再附上 php 代码供大家使用：
https://github.com/PaddyDu/hyb_claude_api_fix

apache站点配置重写：
# Rewrite 规则
        
```
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.+)$
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
RewriteRule ^v1/chat/completions$ /proxy_oai.php [L,QSA]
```
希望公益站长看到可以直接优化下，这样就不用中转了。

有需要就留下赞吧。
