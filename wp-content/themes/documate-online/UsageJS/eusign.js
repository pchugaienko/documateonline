//=============================================================================

const euConstants = require('./consts');

/* Підключення криптобібліотеки JavaScript */
var fs = require('fs');
eval(fs.readFileSync('./lib/euscpt.js')+'');
eval(fs.readFileSync('./lib/euscpm.js')+'');
eval(fs.readFileSync('./lib/euscp.js')+'');

//=============================================================================

/* Признак, що криптобібліотека завантажена */
var g_isLibraryLoaded = false;

//=============================================================================

/* Функція зворотнього виклику, що викликається після завантаження бібліотеки */
/* УВАГА! Функції бібліотеки можна викликати тільки після виклику цієї функції */
function EUSignCPModuleInitialized(isInitialized) {
	g_isLibraryLoaded = isInitialized;
}

//=============================================================================

/* Клас, що реалізує основні операції необхідні для взаємодії з ІСЕІ (id.gov.ua) */
class EndUserSignModule {
	constructor(CAs, CACertificates, PKeySettings) {
		this.euSign = EUSignCP();
		this.context = null;
		this.pkContext = null;
		this.CAs = CAs;
		this.CACertificates = CACertificates;
		this.PKeySettings = PKeySettings;
	}

	/* Функція очікування завантаження криптобібліотеки */
	_load() {
		var pThis = this;

		return new Promise(function(resolve, reject) {
			var checkLoad = function() {
				if (g_isLibraryLoaded) {
					resolve();
					return;
				}

				setTimeout(function() {
					checkLoad();
				}, 1);
			};

			checkLoad();
		});
	}

	/* Функція ініціалізації криптобібліотеки, встановлення налаштувань та зчитування ос. ключа */
	_initialize() {
		var pThis = this;
		var euSign = pThis.euSign;

		return new Promise(function(resolve, reject) {
			pThis._load()
			.then(function() {
				/* Перевірка необхідності ініціалізації криптографічної бібліотеки */
				if (!euSign.IsInitialized()) {
					/* Ініціалізація криптографічної бібліотеки */
					euSign.Initialize();
				}

				/* Перевірка необхідності встановлення налаштувань крипт. бібліотеки */
				if (euSign.DoesNeedSetSettings()) {
					/* Зчитування файла з налаштуваннями ЦСК */
					var CAs = JSON.parse(fs.readFileSync(pThis.CAs), 'utf8');

					/* Отримання налаштувань ЦСК для ос. ключа */
					var CASettings = null;
					for (var i = 0; i < CAs.length; i++) {
						for (var j = 0; j < CAs[i].issuerCNs.length; j++) {
							if (pThis.PKeySettings.CACommonName == CAs[i].issuerCNs[j]) {
								CASettings = CAs[i];
								break;
							}
						}

						if (CASettings)
							break;
					}

					/* Встановлення параметрів за замовчанням */
					pThis._setSettings(CAs, CASettings);

					/* Завантаження сертифікатів ЦСК */
					pThis._loadCertificates(pThis.CACertificates);
				}

				/* Перевірка чи зчитано ос. ключ */
				if (pThis.pkContext == null) {
					/* Імпорт сертифікатів ос. ключа */
					pThis._loadCertificates(pThis.PKeySettings.certificates);

					/* Зчитування ос. ключа */
					var pKeyData = new Uint8Array(fs.readFileSync(
						pThis.PKeySettings.filePath));
					pThis.pkContext = euSign.CtxReadPrivateKeyBinary(
						pThis.context, pKeyData, 
						pThis.PKeySettings.password)
				}

				resolve();
			})
			.catch(function(e) {
				reject(e);
			});
		});
	}

	/* Функція встановлення налаштувань криптобібліотеки */
	_setSettings(CAs, CASettings) {
		var pThis = this;
		var euSign = pThis.euSign;

		var offline = true;
		var useOCSP = false;
		var useCMP = false;

		offline = ((CASettings == null) || 
			(CASettings.address == "")) ?
			true : false;
		useOCSP = (!offline && (CASettings.ocspAccessPointAddress != ""));
		useCMP = (!offline && (CASettings.cmpAddress != ""));

		euSign.SetJavaStringCompliant(true);
		euSign.SetCharset('UTF-8');

		var settings = euSign.CreateFileStoreSettings();
		settings.SetPath('');
		settings.SetSaveLoadedCerts(false);
		euSign.SetFileStoreSettings(settings);

		settings = euSign.CreateModeSettings();
		settings.SetOfflineMode(offline);
		euSign.SetModeSettings(settings);

		settings = euSign.CreateProxySettings();
		euSign.SetProxySettings(settings);

		settings = euSign.CreateTSPSettings();
		settings.SetGetStamps(!offline);
		if (!offline) {
			if (CASettings.tspAddress != "") {
				settings.SetAddress(CASettings.tspAddress);
				settings.SetPort(CASettings.tspAddressPort);
			}
		}
		euSign.SetTSPSettings(settings);

		settings = euSign.CreateOCSPSettings();
		if (useOCSP) {
			settings.SetUseOCSP(true);
			settings.SetBeforeStore(true);
			settings.SetAddress(CASettings.ocspAccessPointAddress);
			settings.SetPort("80");
		}
		euSign.SetOCSPSettings(settings);

		settings = euSign.CreateOCSPAccessInfoModeSettings();
		settings.SetEnabled(true);
		euSign.SetOCSPAccessInfoModeSettings(settings);
		settings = euSign.CreateOCSPAccessInfoSettings();
		for (var i = 0; i < CAs.length; i++) {
			settings.SetAddress(CAs[i].ocspAccessPointAddress);
			settings.SetPort(CAs[i].ocspAccessPointPort);

			for (var j = 0; j < CAs[i].issuerCNs.length; j++) {
				settings.SetIssuerCN(CAs[i].issuerCNs[j]);
				euSign.SetOCSPAccessInfoSettings(settings);
			}
		}

		settings = euSign.CreateCMPSettings();
		settings.SetUseCMP(useCMP);
		if (useCMP) {
			settings.SetAddress(CASettings.cmpAddress);
			settings.SetPort("80");
		}
		euSign.SetCMPSettings(settings);

		settings = euSign.CreateLDAPSettings();
		euSign.SetLDAPSettings(settings);
		
		pThis.context = euSign.CtxCreate();
	}

	/* Функція імпорту сертифікатів до сховища сертифікатів та СВС криптобібліотеки */
	_loadCertificates(certsFilePathes) {
		var pThis = this;
		var euSign = pThis.euSign;
		if (!certsFilePathes)
			return;

		for (var i = 0; i < certsFilePathes.length; i++) {
			var path = certsFilePathes[i];
			var data = new Uint8Array(fs.readFileSync(path));
			if (path.substr(path.length - 3) == 'p7b') {
				euSign.SaveCertificates(data);
			} else {
				euSign.SaveCertificate(data);
			}
		}
	}

	/* Функція отримання сертифіката отримувача на який буде зашифровано відповідь від ІСЕІ (id.gov.ua) */
	/* Функція повертає сертифікат шифрування у вигляді base64 */
	getEnvelopCertificate() {
		var pThis = this;

		return new Promise(function(resolve, reject) {
			pThis._initialize()
			.then(function() {
				var envCert = pThis.euSign.CtxGetOwnCertificate(
					pThis.pkContext, EU_CERT_KEY_TYPE_DSTU4145, 
					EU_KEY_USAGE_KEY_AGREEMENT);
				envCert = pThis.euSign.Base64Encode(envCert.GetData());

				resolve(envCert);
			})
			.catch(function(e) {
				reject(e);
			})
		});
	}

	/* Функція розшифрування даних отриманих від ІСЕІ (id.gov.ua). */
	/* Функція повертає інформацію про відправника та дані у вигляді EndUserSenderInfo */
	developData(envData) {
		var pThis = this;

		return new Promise(function(resolve, reject) {
			pThis._initialize()
			.then(function() {
				var senderInfo = pThis.euSign.CtxDevelopData(
					pThis.pkContext, envData, null);
				
				console.log(senderInfo);
				console.log(pThis.euSign.ArrayToString(senderInfo.GetData()));

				senderInfo.data = pThis.euSign.ArrayToString(senderInfo.GetData());

				resolve(senderInfo);
			})
			.catch(function(e) {
				reject(e);
			})
		});
	}
}

//=============================================================================

var g_euSign = new EndUserSignModule(
	euConstants.CAS, euConstants.CA_CERTIFICATES,
	euConstants.PKEY_PARAMETERS);

//=============================================================================

module.exports = g_euSign;

//=============================================================================
