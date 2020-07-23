<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\Provinces as ProvinceResourceCollection;
use App\Http\Resources\Cities as CityResourceCollection;
use App\Models\Province;
use App\Models\City;
use App\Models\Book;
use Auth;
class ShopController extends Controller
{
    public function provinces(){
        return new ProvinceResourceCollection(Province::get());
    }

    public function cities(){
        return new CityResourceCollection(City::get());
    }

    public function shipping(Request $request)
    {
        $user = Auth::user();
        $status = "error";
        $message = "";
        $data = null;
        $code = 200;
        if ($user) {
            $this->validate($request, [
                'name' => 'required',
                'address' => 'required',
                'phone' => 'required',
                'province_id' => 'required',
                'city_id' => 'required'
            ]);
            $user->name = $request->name;
            $user->address = $request->address;
            $user->phone = $request->phone;
            $user->province_id = $request->province_id;
            $user->city_id = $request->city_id;
            if ($user->save()) {
                $status = "success";
                $message = "Update Shipping Success";
                $data = $user->toArray();
            } else {
                $message = "Update Shipping failed";
            }
        }else {
            $message = "user not found";
        }
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    public function couriers()
    {
        $couriers= [
            ['id'=> 'jne', 'text'=> 'JNE'],
            ['id'=> 'tiki', 'text'=> 'TIKI'],
            ['id'=> 'pos', 'text'=> 'POS'],
        ];

        return response()->json([
            'status'=>'success',
            'message'=>'couriers',
            'data'=>$couriers
        ]);
    }

    protected function validateCart($carts)
    {
        $safe_carts = [];
        $total=[
            'quantity_before' => 0,
            'quantity' => 0,
            'price' => 0,
            'weight' => 0
        ];
        $idx = 0;
        foreach ($carts as $cart) {
            $id = (int)$cart['id'];
            $quantity = (int) $cart['quantity'];
            $total['quantity_before'] += $quantity;
            $book = Book::find($id);
            if ($book) {
                if ($book->stock>0) {
                    $safe_carts[$idx]['id'] = $book->id;
                    $safe_carts[$idx]['title'] = $book->title;
                    $safe_carts[$idx]['cover'] = $book->cover;
                    $safe_carts[$idx]['price'] = $book->price;
                    $safe_carts[$idx]['weight'] = $book->weight;
                    if ($book->stock < $quantity) {
                        $quantity = (int) $book->stock;
                    }
                    $safe_carts[$idx]['quantity'] = $quantity;

                    $total['quantity'] += $quantity;
                    $total['price'] += $book->price * $quantity;
                    $total['weight'] += $book->weight * $quantity;
                    $idx++;
                }
                else {
                    continue;
                }
            }
        }
        return[
            'safe_carts' => $safe_carts,
            'total' => $total
        ];
    }

    protected function getServices($data)
    {
        $url_cost = "https://api.rajaongkir.com/starter/cost";
        $key= "4d1863c842cd732f05e82538ef00f1af";
        $postdata = http_build_query($data);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url_cost,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURLOPT_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $postdata,
            CURLOPT_HTTPHEADER  => [
                "content-type: application/x-www-form-urlencoded",
                "key: ".$key
            ]
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($url);
        return [
            'error' => $error,
            'response' => $response,
        ];
    }

    public function services(Request $request){
        $status = "error";
        $message = "";
        $data = [];
        // Validasi kelengkapan data
        $this->validate([
            'courier' => 'required',
            'carts' => 'required',
        ]);


        $user = Auth::user();
        if ($user) {
            $destination = $user->city_id;
            if ($destination>0) {
                // hardcode, silakan sesuaikan dengan asal pengiriman barangnya
                $origin = 153; // Jaksel
                $courier = $request->courier;
                $carts = $request->carts;
                $carts = json_decode($carts, true); // transformasi dari json menjadi array

                // validasi data belanja
                $validCart= $this->validateCart['safe_carts'];
                $data['safe_carts'] = $validCart['safe_carts'];
                $data['total'] = $validCart['total'];
                $quantity_different = $data['total']['quantity_before']<>$data['total']['quantity'];
                $weight = $validCart['total']['weight'] * 1000;
                if ($weight > 0) {
                    // request courier service API Raja Ongkir
                    $parameter = [
                        "origin" => $origin,
                        'destination' => $destination,
                        "weight" => $weight,
                        "courier" => $courier,
                    ];
                    // cek origin irim ke api SiRajaOngkir melalui fungsi getServices()
                    $respon_services = $this->getServices($parameter);

                    if ($respon_services['error']==null) {
                        $services = [];
                        $response = json_decode($respon_services['response']);
                        $costs = $response->rajaongkir->result[0]->consts;
                        foreach ($costs as $cost) {
                            $service_name = $cost->service;
                            $service_cost = $cost->cost[0]->value;
                            $service_estimation = str_replace('hari', '', trim($cost->cost[0]->etd));
                            $services[] = [
                                'service' => $service_name,
                                'cost' => $service_cost,
                                'estimation' => $service_estimation,
                                'resume' => $service_name . '[Rp. '.number_format($service_cost).', Etd: '.$cost->cost[0]->etd.' day(s)]'
                            ];
                        }
                        // Response
                        if (count($services)>0) {
                            $data['services'] = $services;
                            $status="success";
                            $message = "getting service success";
                        }else{
                            $message = "courier service unavailable";
                        }
                        // Ketika jumlah beli melebihi jumlah stock maka tampilkan warning
                        if ($quantity_different) {
                            $status="warning";
                            $message="Check cart data ".$message;
                        }
                    } else {
                        $message = "CURL Error #:" . $respon_services['error'];
                    }   
                }else {
                    $message = "weight invalid";
                }
            } else {
                $message = "destination not set";
            }
        } else {
            $message = "user not found";
        }

        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data
        ], 200);
            
    }
    

}