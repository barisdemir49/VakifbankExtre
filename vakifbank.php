<?php

/**Vakıfbank hesap hareketlerini çekme. Veritanı bilgilerini kendi verilerinize göre düzenleyiniz
Barış Demir 17.11.2020
Methodlar:
    GetirHareket        : Hesaplar ve hareketlerini getirir
    GetirHareketOzet    :
    GetirDekont         : işlem id değeri verilen hareketin dekontunu base64  pdf olarak getirir
    GetirYatirimHareket :
 */

class ExtreBanka
{

    private $url = 'https://vbservice.vakifbank.com.tr/HesapHareketleri.OnlineEkstre/SOnlineEkstreServis.svc?Wsdl';
    public $SorguBaslangicTarihi = '';
    public $SorguBitisTarihi = '';
    public $soapVersion = SOAP_1_2;
    public $WsaAction = 'Peak.Integration.ExternalInbound.Ekstre/ISOnlineEkstreServis/';
    public $soap = null;
    public $sorgu = [
        'MusteriNo' => '',
        'KurumKullanici' => '',
        'Sifre' => '',
    ];


    function __construct()
    {
        $info = _sql_get(array('select' => '*', 'from' => 'crm_member_finans_bankexre', 'where' => "library='vakifbank'"));
        if (!$info) return false;
        $this->sorgu['MusteriNo'] = $info['musterino'];
        $this->sorgu['KurumKullanici'] = $info['username'];
        $this->sorgu['Sifre'] = $info['passwd'];
    }

    private function set_soap()
    {
        $this->soap = new SoapClient($this->url, array('soap_version' => $this->soapVersion, 'trace' => true));
        $NS_ADDR = 'http://www.w3.org/2005/08/addressing';
        $action = new SoapHeader($NS_ADDR, 'Action', $this->WsaAction, true);
        $to = new SoapHeader($NS_ADDR, 'To', $this->url, false);
        $headerbody = array('Action' => $action, 'To' => $to);
        $this->soap->__setSoapHeaders($headerbody);
    }

    function GetirDekont($id)
    {
        $return = (object) [
            'message' => null,
            'data' => false
        ];
        try {
            $this->sorgu['IslemNo'] = $id;
            $DtoEkstreSorgu = (object)[
                'sorgu' => (object)  $this->sorgu
            ];

            $this->WsaAction .= 'GetirDekont';
            $this->set_soap();
            $result = $this->soap->GetirDekont($DtoEkstreSorgu);
            if ($result and isset($result->GetirDekontResult->IslemKodu)) {
                if ($result->GetirDekontResult->IslemKodu != 'VBD0001') {
                    $return->message = $result->GetirDekontResult->IslemAciklamasi;
                    $return->data = base64_encode($result->GetirDekontResult->DekontListesi->base64Binary);
                    return $return;
                }
            } else
                $return->message = 'Kayıt bulunamadı!';
            return $result;
        } catch (SoapFault $error) {
            $error = json_decode(json_encode($error));
            $return->message = $error->faultstring;
            return $return;
        }
    }

    function GetirHareket($params)
    {

        $return = (object) [
            'banka' => (object) [
                'BankaKodu' => false,
                'BankaAdi' => false,
                'BankaVergiDairesi' => false,
                'BankaVergiNumarasi' => false,
                'IslemKodu' => false,
            ],
            'hesaplar' => false,
            'hareketler' => false,
            'message' => null
        ];
        try {
            if (!$this->sorgu['MusteriNo'] or !$this->sorgu['KurumKullanici'] or !$this->sorgu['Sifre']) {
                $return->message = 'Abi bilgileri yok';
                return $return;
            }

            $this->SorguBaslangicTarihi = isset($params->baslangic) ? $params->baslangic . ' 00:00' : date('Y-m-d 00:00');
            $this->SorguBitisTarihi = isset($params->bitis) ? $params->bitis . ' 23:59' : date('Y-m-d 23:59');
            $HesapNo = isset($params->HesapNo) ? $params->HesapNo : false;
            $HareketTipi = isset($params->HareketTipi) ? $params->HareketTipi : false;
            $EnDusukTutar = isset($params->EnDusukTutar) ? $params->EnDusukTutar : false;
            $EnYuksekTutar = isset($params->EnYuksekTutar) ? $params->EnYuksekTutar : false;

            $this->sorgu['SorguBaslangicTarihi'] = $this->SorguBaslangicTarihi;
            $this->sorgu['SorguBitisTarihi'] = $this->SorguBitisTarihi;
            if ($HesapNo) $this->sorgu['HesapNo'] = $HesapNo;
            if ($HareketTipi)  $this->sorgu['HareketTipi'] = $HareketTipi;
            if ($EnDusukTutar)  $this->sorgu['EnDusukTutar'] = $EnDusukTutar;
            if ($EnYuksekTutar)  $this->sorgu['EnYuksekTutar'] = $EnYuksekTutar;


            $DtoEkstreSorgu = (object)[
                'sorgu' => (object)  $this->sorgu
            ];

            $this->WsaAction .= 'GetirHareket';
            $this->set_soap();
            $result = $this->soap->GetirHareket($DtoEkstreSorgu);
            $result = $result->GetirHareketResult;
            if ($result and isset($result->IslemKodu)) {
                if ($result->IslemKodu != 'VBB0001') {
                    $return->message = $result->IslemAciklamasi;
                    return $return;
                }
            } else {
                $return->message = 'Kayıt bulunamadı';
                return $return;
            }
            $return->banka->BankaKodu = $result->BankaKodu;
            $return->banka->BankaAdi = $result->BankaAdi;
            $return->banka->BankaVergiDairesi = $result->BankaVergiDairesi;
            $return->banka->IslemKodu = $result->IslemKodu;
            $return->banka->BankaVergiNumarasi = $result->BankaVergiNumarasi;

            foreach ($result->Hesaplar as $key => $hesaplar) {
                /**tek hesap seçilmişse */
                if ($HesapNo) $hesaplar = $result->Hesaplar;
                foreach ($hesaplar as $hesap) {
                    $return->hesaplar[$hesap->HesapNo] = (object)[
                        'HesapNo' => $hesap->HesapNo,
                        'SubeAdi' => $hesap->SubeAdi,
                        'SubeKodu' => $hesap->SubeKodu,
                        'SonHareketTarihi' => $hesap->SonHareketTarihi,
                        'HesapRumuzu' => $hesap->HesapRumuzu,
                        'HesapNoIban' => $hesap->HesapNoIban,
                        'AcilisBakiye' => $hesap->AcilisBakiye,
                        'CariBakiye' => $hesap->CariBakiye,
                        'KullanilabilirBakiye' => $hesap->KullanilabilirBakiye,
                    ];
                    if (count($hesap->Hareketler) > 0 and isset($hesap->Hareketler->DtoEkstreHareket))
                        foreach ($hesap->Hareketler->DtoEkstreHareket as $hareket)
                            $return->hareketler[$hesap->HesapNo][] = $hareket;
                }
            }
            return $return;
        } catch (SoapFault $error) {
            $error = json_decode(json_encode($error));
            $return->message = $error->faultstring;
            return $return;
        }
    }

    function GetirHareketOzet()
    {
    }

    function GetirYatirimHareket()
    {
    }
}
