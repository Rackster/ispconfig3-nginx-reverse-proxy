#ISPConfig3 - nginx Reverse Proxy Plugin

This plugin allows you to run nginx in front of apache2 as a reverse proxy on servers managed through the ISPConfig3 Control Panel.


##How it works

In general, it just creates the nginx vhost files for your sites.

Afterwards, all requests to port 80 or 443 (default http(s) ports) are fetched by nginx rather than apache2 and passed to the apache2 backend - with nginx's built-in *proxy_pass* feature.


##How to install

Please refer to the wiki which can be found here: [Wiki](https://github.com/Rackster/ispconfig-3-nginx-reverse-proxy/wiki)


##Contribution

Feel free to be an active part of the project, either by testing and creating issues or forking and sending pull requests.


##Disclaimer

I am in no way responsible for any damage caused to your system by using the plugin.    
Usage at you own risk!