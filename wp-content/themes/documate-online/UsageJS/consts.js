// Параметри підключення до ІСЕІ (id.gov.ua)
const CLIENT_ID = '';
const CLIENT_SECRET = '';
const SERVER_URI  = "https://test.id.gov.ua";
const REDIRECT_URI = '';
/* Шлях до файла CAs.json з налаштуваннями серверів ЦСК */
var CAS = "./data/CAs.Test.CAO.json"

/* Масив з шляхами до файлів кореневих сертификатів ЦЗО та ЦСК */
var CA_CERTIFICATES = [
	"./data/CACertificates.Test.CAO.p7b",
];

/* Параметри ос. ключа, який використовується для розшифрування відповідей від ІСЕІ (id.gov.ua) */
var PKEY_PARAMETERS = {
	filePath: "./data/Key-6.dat",	/* Шлях до файла з ос. ключем */
	password: "",			/* Пароль до файла з ос. ключем */
	certificates: [					/* Масив з шляхами до файлів сертифікатів ос. ключа */
	],
	CACommonName: "Тестовий надавач електронних довірчих послуг" /* Ім'я ЦСК, що видав сертифікат ос. ключа*/
};

module.exports = {
    CLIENT_ID,
    CLIENT_SECRET,
    SERVER_URI,
    REDIRECT_URI,
    CAS,
    CA_CERTIFICATES,
    PKEY_PARAMETERS
};
