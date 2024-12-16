//=============================================================================

const http = require('http');
const url = require('url');
const OAuth2 = require('./oauth2');
const euConstants = require('./consts');
const port = process.argv[2] || 80;

//=============================================================================

const oauth2 = new OAuth2(
    euConstants.CLIENT_ID, euConstants.CLIENT_SECRET, 
    euConstants.SERVER_URI, euConstants.REDIRECT_URI);

//=============================================================================

http.createServer(function (req, res) {
    console.log(`${req.method} ${req.url}`);

    const parsedUrl = url.parse(req.url);
    let pathname = `.${parsedUrl.pathname}`;

    if (pathname == './index.html' || pathname == './') {
        const html = 
            '<!DOCTYPE html>\
            <html xmlns="http://www.w3.org/1999/xhtml">\
            <head>\
                <title>Тестування автентифікації</title>\
            </head>\
            <body>\
                <input id="auth" type="button" value="Authenticate" onclick="location.href=\'' + oauth2.getAuthURI() + '\'"/>\
            </body>\
            </html>';

        res.setHeader('Content-type', 'text/html; charset=utf-8');
        res.end(html);
    } else if (pathname == './dologin') {
        var requestURL = url.parse(req.url, true);
		var code = requestURL.query['code'];

        oauth2.getAuthorizationCode(code)
        .then(function(response) {
            return oauth2.getUserInfo(
                response.user_id, response.access_token);
        })
        .then(function(response) {
            const html = 
            '<!DOCTYPE html>\
            <html xmlns="http://www.w3.org/1999/xhtml">\
            <head>\
                <title>Тестування автентифікації</title>\
            </head>\
            <body>\
               ' + 'Тип автентифікації: '+ response.auth_type + '<br>' + '\
               ' + 'Користувач: '+ response.subjectcn + '<br>' + '\
               ' + 'ЄДРПОУ: '+ response.edrpoucode + '<br>' + '\
               ' + 'ДРФО: '+ response.drfocode + '<br>' + '\
            </body>\
            </html>';

            res.setHeader('Content-type', 'text/html; charset=utf-8');
            res.end(html);
        })
        .catch(function(e) {
            res.statusCode = 500;
            res.end('Error: ' + e);
        })
    } else {
        res.statusCode = 404;
        res.end(`File ${pathname} not found!`);
        return;
    }
}).listen(parseInt(port));

//=============================================================================

console.log(`Server listening on port ${port}`);

//=============================================================================
