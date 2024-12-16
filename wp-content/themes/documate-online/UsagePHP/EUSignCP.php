<?php

//================================================================================

class EUOwnerInfo
{

//================================================================================

	public $issuer				= '';
	public $issuerCN			= '';
	public $serial				= '';
	public $subject				= '';
	public $subjCN				= '';
	public $subjOrg				= '';
	public $subjOrgUnit			= '';
	public $subjTitle			= '';
	public $subjState			= '';
	public $subjLocality		= '';
	public $subjFullName		= '';
	public $subjAddress			= '';
	public $subjPhone			= '';
	public $subjEMail			= '';
	public $subjDNS				= '';
	public $subjEDRPOUCode		= '';
	public $subjDRFOCode		= '';

//================================================================================

}

//================================================================================

class EUSenderInfo
{

//================================================================================

	public $signTime			= null;
	public $useTSP				= false;
	public $ownerInfo			= null;

//================================================================================

	function __construct()
	{
		$this->ownerInfo = new EUOwnerInfo();
	}

//================================================================================

}

//================================================================================

class EUSignCP 
{

//================================================================================

	const EM_RESULT_OK							= 0;
	const EM_RESULT_ERROR						= 1;
	const EM_RESULT_ERROR_WRONG_PARAMS			= 2;
	const EM_RESULT_ERROR_INITIALIZED			= 3;

	const EM_ENCODING_CP1251					= 1251;
	const EM_ENCODING_UTF8						= 65001;

	const EU_ERROR_NONE						 	= 0x0000;
	const EU_ERROR_UNKNOWN						= 0xFFFF;
	const EU_ERROR_NOT_SUPPORTED				= 0xFFFE;

	const EU_ERROR_NOT_INITIALIZED				= 0x0001;
	const EU_ERROR_BAD_PARAMETER				= 0x0002;
	const EU_ERROR_LIBRARY_LOAD				 	= 0x0003;
	const EU_ERROR_READ_SETTINGS				= 0x0004;
	const EU_ERROR_TRANSMIT_REQUEST			 	= 0x0005;
	const EU_ERROR_MEMORY_ALLOCATION			= 0x0006;
	const EU_WARNING_END_OF_ENUM				= 0x0007;
	const EU_ERROR_PROXY_NOT_AUTHORIZED		 	= 0x0008;
	const EU_ERROR_NO_GUI_DIALOGS				= 0x0009;
	const EU_ERROR_DOWNLOAD_FILE				= 0x000A;
	const EU_ERROR_WRITE_SETTINGS				= 0x000B;
	const EU_ERROR_CANCELED_BY_GUI				= 0x000C;
	const EU_ERROR_OFFLINE_MODE				 	= 0x000D;

	const EU_ERROR_KEY_MEDIAS_FAILED			= 0x0011;
	const EU_ERROR_KEY_MEDIAS_ACCESS_FAILED	 	= 0x0012;
	const EU_ERROR_KEY_MEDIAS_READ_FAILED		= 0x0013;
	const EU_ERROR_KEY_MEDIAS_WRITE_FAILED		= 0x0014;
	const EU_WARNING_KEY_MEDIAS_READ_ONLY		= 0x0015;
	const EU_ERROR_KEY_MEDIAS_DELETE			= 0x0016;
	const EU_ERROR_KEY_MEDIAS_CLEAR				= 0x0017;
	const EU_ERROR_BAD_PRIVATE_KEY				= 0x0018;

	const EU_ERROR_PKI_FORMATS_FAILED			= 0x0021;
	const EU_ERROR_CSP_FAILED					= 0x0022;
	const EU_ERROR_BAD_SIGNATURE				= 0x0023;
	const EU_ERROR_AUTH_FAILED					= 0x0024;
	const EU_ERROR_NOT_RECEIVER				 	= 0x0025;

	const EU_ERROR_STORAGE_FAILED				= 0x0031;
	const EU_ERROR_BAD_CERT						= 0x0032;
	const EU_ERROR_CERT_NOT_FOUND				= 0x0033;
	const EU_ERROR_INVALID_CERT_TIME			= 0x0034;
	const EU_ERROR_CERT_IN_CRL					= 0x0035;
	const EU_ERROR_BAD_CRL						= 0x0036;
	const EU_ERROR_NO_VALID_CRLS				= 0x0037;

	const EU_ERROR_GET_TIME_STAMP				= 0x0041;
	const EU_ERROR_BAD_TSP_RESPONSE			 	= 0x0042;
	const EU_ERROR_TSP_SERVER_CERT_NOT_FOUND	= 0x0043;
	const EU_ERROR_TSP_SERVER_CERT_INVALID		= 0x0044;

	const EU_ERROR_GET_OCSP_STATUS				= 0x0051;
	const EU_ERROR_BAD_OCSP_RESPONSE			= 0x0052;
	const EU_ERROR_CERT_BAD_BY_OCSP				= 0x0053;
	const EU_ERROR_OCSP_SERVER_CERT_NOT_FOUND	= 0x0054;
	const EU_ERROR_OCSP_SERVER_CERT_INVALID		= 0x0055;

	const EU_ERROR_LDAP_ERROR					= 0x0061;

	const EU_SESSION_EXPIRE_TIME				= 3600;

//================================================================================

	private $pkEnvCert							= null;

//================================================================================

	private function resultToErrorCode(
		$result, 
		$errorCode)
	{
		switch ($result) 
		{
			case EUSignCP::EM_RESULT_OK:
				return EUSignCP::EU_ERROR_NONE;

			case EUSignCP::EM_RESULT_ERROR:
				return $errorCode;

			case EUSignCP::EM_RESULT_ERROR_WRONG_PARAMS:
				return EUSignCP::EU_ERROR_BAD_PARAMETER;

			case EUSignCP::EM_RESULT_ERROR_INITIALIZED:
				return EUSignCP::EU_ERROR_NOT_INITIALIZED;

			default:
				return EUSignCP::EU_ERROR_UNKNOWN;
		}
	}

//--------------------------------------------------------------------------------

	public function getErrorDescription(
		$errorCode)
	{
		if ($errorCode == EUSignCP::EU_ERROR_NOT_INITIALIZED)
			return 'Криптографічну бібліотеку не ініціалізовано';

		$errorDescription = '';

		euspe_geterrdescr(
			$errorCode,
			$errorDescription
		);

		return $errorDescription;
	}

//--------------------------------------------------------------------------------

	public function initialize(
		$pkFilePath,
		$pkPassword,
		$pkEnvCertFilePath)
	{
		$errorCode = EUSignCP::EU_ERROR_UNKNOWN;

		$result = euspe_setcharset(
			EUSignCP::EM_ENCODING_UTF8
		);
		if ($result != EUSignCP::EM_RESULT_OK)
		{
			$errorCode = $this->resultToErrorCode(
				$result, 
				$errorCode
			);

			return $errorCode;
		}

		$result = euspe_init(
			$errorCode
		);
		if ($result != EUSignCP::EM_RESULT_OK)
		{
			$errorCode = $this->resultToErrorCode(
				$result, 
				$errorCode
			);

			return $errorCode;
		}

		$isPKeyReaded = false;
		$result = euspe_isprivatekeyreaded(
			$isPKeyReaded,
			$errorCode
		);
		if ($result != EUSignCP::EM_RESULT_OK)
		{
			$errorCode = $this->resultToErrorCode(
				$result, 
				$errorCode
			);

			return $errorCode;
		}
		
		if (!$isPKeyReaded)
		{
			$result = euspe_readprivatekeyfile(
				$pkFilePath, 
				$pkPassword,
				$errorCode
			);
			if ($result != EUSignCP::EM_RESULT_OK)
			{
				$errorCode = $this->resultToErrorCode(
					$result, 
					$errorCode
				);

				return $errorCode;
			}
		}
		
		$pkEnvCert = file_get_contents(
			$pkEnvCertFilePath, 
			FILE_USE_INCLUDE_PATH
		);
		$this->pkEnvCert = base64_encode($pkEnvCert);

		return EUSignCP::EU_ERROR_NONE;
	}

	public function getEnvelopCert() {
		return $this->pkEnvCert;
	}

//--------------------------------------------------------------------------------

	public function develop(
		$envelopedData,
		&$data,
		&$senderInfo)
	{
		$senderInfo = new EUSenderInfo();
		$data = '';
		$errorCode = EUSignCP::EU_ERROR_UNKNOWN;

		$result = euspe_develop(
			$envelopedData,
			$data,
			$senderInfo->signTime,
			$senderInfo->useTSP,
			$senderInfo->ownerInfo->issuer,
			$senderInfo->ownerInfo->issuerCN,
			$senderInfo->ownerInfo->serial,
			$senderInfo->ownerInfo->subject,
			$senderInfo->ownerInfo->subjCN,
			$senderInfo->ownerInfo->subjOrg,
			$senderInfo->ownerInfo->subjOrgUnit,
			$senderInfo->ownerInfo->subjTitle,
			$senderInfo->ownerInfo->subjState,
			$senderInfo->ownerInfo->subjLocality,
			$senderInfo->ownerInfo->subjFullName,
			$senderInfo->ownerInfo->subjAddress,
			$senderInfo->ownerInfo->subjPhone,
			$senderInfo->ownerInfo->subjEMail,
			$senderInfo->ownerInfo->subjDNS,
			$senderInfo->ownerInfo->subjEDRPOUCode,
			$senderInfo->ownerInfo->subjDRFOCode,
			$errorCode
		);
		if ($result != EUSignCP::EM_RESULT_OK)
		{
			$errorCode = $this->resultToErrorCode(
				$result, 
				$errorCode
			);

			return $errorCode;
		}

		return EUSignCP::EU_ERROR_NONE;
	}

//================================================================================

}

//================================================================================

?>