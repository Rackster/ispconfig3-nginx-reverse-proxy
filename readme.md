# ISPConfig3 - Nginx Reverse Proxy

This plugin allows you to run Nginx in front of Apache2 as a reverse proxy on servers managed through the ISPConfig3 Control Panel.


## How it works

In general, it just creates the Nginx vhost files for your sites.

Afterwards, all requests to port 80 or 443 (default http(s) ports) are fetched by Nginx rather than Apache2 and passed to the Apache2 backend - with Nginx's built-in *proxy_pass* feature.


## How to install

Please refer to the wiki which can be found here: [Wiki](https://github.com/Rackster/ispconfig3-nginx-reverse-proxy/wiki)


## Contribution

Feel free to be an active part of the project, either by testing and creating issues or forking and sending pull requests.


## Disclaimer

I am in no way responsible for any damage caused to your system by using the plugin.
Usage at you own risk!


## Donation

[Donate via Paypal](https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=V44LFF7R79DS4&lc=CH&item_name=Rackster%20Internet%20Services&item_number=ispconfig3%2dnginx%2dreverse%2dproxy&no_note=0&cn=Mitteilung%3a&no_shipping=1&rm=1&return=https%3a%2f%2fgithub%2ecom%2fRackster%2fispconfig3%2dnginx%2dreverse%2dproxy&currency_code=CHF&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted)