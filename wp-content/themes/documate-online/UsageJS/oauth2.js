//=============================================================================

const https = require('https');
const euSign = require('./eusign');

//=============================================================================

class AuthorizationCodeResponse {
    constructor(data) {
        let response = JSON.parse(data);
        this.access_token = response['access_token'];
        this.token_type = response['token_type'];
        this.expires_in = response['expires_in'];
        this.refresh_token = response['refresh_token'];
        this.user_id = response['user_id'];
    }
}

class EnvelopedUserInfoResponse {
    constructor(data) {
        let response = JSON.parse(data);
        this.encryptedUserInfo = response['encryptedUserInfo'];
        this.error = response['error'];
        this.message = response['message'];
    }
}

class UserInfoResponse {
    constructor(data) {
        let response = JSON.parse(data);
        this.auth_type = response['auth_type'];
		this.issuer = response['issuer'];
		this.issuercn = response['issuercn'];
		this.serial = response['serial'];
		this.subject = response['subject'];
		this.subjectcn = response['subjectcn'];
		this.locality = response['locality'];
		this.state = response['state'];
		this.o = response['o'];
		this.ou = response['ou'];
		this.title = response['title'];
		this.lastname = response['lastname'];
		this.middlename = response['middlename'];
		this.givenname = response['givenname'];
		this.email = response['email'];
		this.address = response['address'];
		this.phone = response['phone'];
		this.dns = response['dns'];
		this.edrpoucode = response['edrpoucode'];
		this.drfocode = response['drfocode'];
    }
}

//=============================================================================

class OAuth2 {
    constructor(clientId, secret, uri, redirectURI) {
        this.clientId = clientId;
        this.secret = secret;
        this.uri = uri;
        this.redirectURI = redirectURI;
    }

    makeRequest(uri) {
        return new Promise(function(resolve, reject) {
            https.get(uri, (resp) => {
                let data = [];
        
                resp.on('data', (chunk) => {
                    data.push(chunk);
                });
        
                resp.on('end', () => {
                    data = Buffer.concat(data);
                    resolve(new Uint8Array(new Buffer(data)));
                });
            }).on("error", (e) => {
                console.log("OAuth2::makeRequest (error): " + e.message);
                reject(e);
            });
        });
    }

    /* Функція отримання URL для авторизації */
    getAuthURI() {
        var pThis = this;
        return pThis.uri +
            "?response_type=code" + 
            "&state=xyz1234567890" +
            "&scope=" +
            "&client_id=" + pThis.clientId +
            "&redirect_uri=" + pThis.redirectURI;
    }

    /* Функція отримання токену доступу за кодом */
    getAuthorizationCode(code) {
        var pThis = this;

        return new Promise(function(resolve, reject) {
            var uri = pThis.uri + 
                "/get-access-token" +
                "?grant_type=authorization_code" +
                "&client_id=" + pThis.clientId +
                "&client_secret=" + pThis.secret +
                "&code=" + code;

            pThis.makeRequest(uri)
            .then(function(result) {
                result = Buffer.from(result).toString('utf-8');
                resolve(new AuthorizationCodeResponse(result));
            })
            .catch(function(e) {
                console.log("OAuth2::getAuthorizationCode (error): " + e);
                reject(e);
            });
        });
	}

    /* Функція отримання інформації про користувача */
    getUserInfo(userId, accessToken) {
        var pThis = this;

        return new Promise(function(resolve, reject) {
            euSign.getEnvelopCertificate() // Отримання сертифікату шифрування отримувача зашифрованих даних
            .then(function(cert) {
                var uri = pThis.uri + 
                    "/get-user-info" +
			        "?fields=issuer,issuercn,serial,subject,subjectcn," +
				    "locality,state,o,ou,title,lastname,middlename," +
				    "givenname,email,address,phone,dns,edrpoucode,drfocode" +
			        "&user_id=" + userId +
			        "&access_token=" + accessToken + 
                    "&cert=" + encodeURIComponent(encodeURIComponent(cert));

                return pThis.makeRequest(uri);
            })
            .then(function(result) {
                result = Buffer.from(result).toString('utf-8');
                var response = new EnvelopedUserInfoResponse(result);
                
                if (!response.encryptedUserInfo) {
                    throw response.message + '(' + response.error + ')';
                }

                /* Розшифрування отриманих даних */
                return euSign.developData(response.encryptedUserInfo);
            })
            .then(function(result) {
                console.log("OAuth2::getUserInfo (result): " + result);

                resolve(new UserInfoResponse(result.data));
            })
            .catch(function(e) {
                console.log("OAuth2::getUserInfo (error): " + e);
                reject(e);
            });
        });
	}
}

//=============================================================================

module.exports = OAuth2;

//=============================================================================
