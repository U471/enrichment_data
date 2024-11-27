<?php
defined('BASEPATH') or exit('No direct script access allowed');

class EnrichmentModel extends CI_Model
{

    // Save the enrichment request in the tblrequest_enrichment table
    public function saveEnrichmentRequest($data)
    {
        $data['request_id'] = $this->generateRequestId($data['user_id_fk']);
        $this->db->insert('tblrequest_enrichment', $data);

        return $this->db->insert_id();
    }

    // Generate a random request ID based on user ID
    private function generateRequestId($user_id_fk)
    {
        return $user_id_fk . '-' . uniqid();
    }

    // Call the first API (Reverse IP Append)
    public function callAppendAPI($data_chunk)
    {
        $api_url = 'https://secureapi.datazapp.com/Appendv2';
        $params = [
            "ApiKey" => "NKBTHXMFEJ",
            "IsMaximizedAppend" => true,
            "AppendModule" => "ReverseIPAppend",
            "AppendType" => 4,
            "Data" => $data_chunk
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $api_response = curl_exec($ch);
        curl_close($ch);

        return json_decode($api_response, true);
    }

    // Save enrichment data into tblenrichment_data table
    public function saveEnrichmentData($data, $request_id, $user_id_fk)
    {
        $insert_data = [];
        foreach ($data as $row) {
            $insert_data[] = [
                'request_id_fk'             => $request_id,
                'user_id_fk'                => $user_id_fk,
                'd_first_name'              => $row['FirstName'] ?? null,
                'd_last_name'               => $row['LastName'] ?? null,
                'd_address'                 => $row['Address'] ?? null,
                'd_address_2'               => $row['Address2'] ?? null,
                'd_email'                   => $row['Email'] ?? null,
                'd_cell'                    => $row['Cell'] ?? null,
                'd_cell_dnc'                => $row['Cell_DNC'] ?? null,
                'd_phone'                   => $row['Phone'] ?? null,
                'd_phone_dnc'               => $row['Phone_DNC'] ?? null,
                'd_state'                   => $row['State'] ?? null,
                'd_city'                    => $row['City'] ?? null,
                'd_country'                 => $row['Country'] ?? null,
                'd_zip_code'                => $row['ZipCode'] ?? null,
                'd_ip'                      => $row['IP'] ?? null,
                'd_ip_country'              => $row['IPCountry'] ?? null,
                'd_ip_state'                => $row['IPState'] ?? null,
                'd_ip_city'                 => $row['IPCity'] ?? null,
                'd_ip_zip_code'             => $row['IPZipCode'] ?? null,
                'd_ip_latitude'             => isset($row['IPLatitude']) ? (float) $row['IPLatitude'] : null,
                'd_ip_longitude'            => isset($row['IPLongitude']) ? (float) $row['IPLongitude'] : null,
                'd_isp'                     => $row['ISP'] ?? null,
                'd_organization'            => $row['Organization'] ?? null,
                'd_ip_type'                 => $row['IPType'] ?? null,
                'd_is_proxy'                => $row['IsProxy'] ?? null,
                'd_last_seen_date'          => isset($row['LastSeenDate']) ? date('Y-m-d H:i:s', strtotime($row['LastSeenDate'])) : null,
                'd_address_status'          => $row['AddressStatus'] ?? null,
                'd_address_type'            => $row['AddressType'] ?? null,
                'd_residential_address_flag' => $row['ResidentialAddressFlag'] ?? null,
                'd_confidence'              => $row['Confidence'] ?? null,
            ];

            // $this->db->insert('tblenrichment_data', $insert_data);
        }
        if (!empty($insert_data)) {
            $this->db->insert_batch('tblenrichment_data', $insert_data);
            return true;
        }

        return false;
    }

    // Call the second API (Process IP Addresses)
    public function callProcessIPAPI($ip_addresses)
    {
        $api_id = 'LkyzKHjNNx4zkAZ2wKsFea**nSAcwXpxhQ0PC2lXxuDAZ-**';
        $process_responses = [];

        foreach ($ip_addresses as $ip) {
            $query_params = [
                'ID' => $api_id,
                'IP' => $ip,
                'FORMAT' => 'JSON'
            ];

            $process_url = 'https://ip2consumer.melissadata.net/v4/WEB/ip2consumer/doIP2Consumer?' . http_build_query($query_params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $process_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            curl_close($ch);

            // Decode the JSON response
            $decoded_response = json_decode($response, true);

            // Check if the 'Records' key exists and is not empty
            if (isset($decoded_response['Records']) && !empty($decoded_response['Records'])) {
                // If the IPAddress is missing, set the IP address
                if (empty($decoded_response['Records'][0]['IPAddress'])) {
                    $decoded_response['Records'][0]['IPAddress'] = $ip;
                }

                // Add the modified records to the response
                $process_responses[] = $decoded_response['Records'];
            }
        }

        return $process_responses;
    }

    // Update tblenrichment_data with processed IP data
    public function updateEnrichmentDataWithIP($response_data, $request_id, $user_id_fk)
    {
        foreach ($response_data as $data) {
            $data = $data[0];
            // Assuming IP address and user info is available in response array
            $update_data = [
                'm_results'                 => $data['Results'] ?? null,
                'm_name_full'               => $data['NameFull'] ?? null,
                'm_name_first'              => $data['NameFirst'] ?? null,
                'm_name_middle'             => $data['NameMiddle'] ?? null,
                'm_name_last'               => $data['NameLast'] ?? null,
                'm_organization'            => $data['Organization'] ?? null,
                'm_address_line_1'          => $data['AddressLine1'] ?? null,
                'm_address_line_2'          => $data['AddressLine2'] ?? null,
                'm_city'                    => $data['City'] ?? null,
                'm_state'                   => $data['State'] ?? null,
                'm_postal_code'             => $data['PostalCode'] ?? null,
                'm_melissa_address_key'     => $data['MelissaAddressKey'] ?? null,
                'm_latitude'                => isset($data['Latitude']) ? (float) $data['Latitude'] : null,
                'm_longitude'               => isset($data['Longitude']) ? (float) $data['Longitude'] : null,
                'm_phone_number'            => $data['PhoneNumber'] ?? null,
                'm_email_address'           => $data['EmailAddress'] ?? null,
                'm_ip_address'              => $data['IPAddress'] ?? null,
                'm_isp_name'                => $data['ISPName'] ?? null,
                'm_domain_name'             => $data['DomainName'] ?? null,
                // Other mappings here
            ];

            $this->db->where('request_id_fk', $request_id);
            $this->db->where('user_id_fk', $user_id_fk);
            $this->db->where('d_ip', $data['IPAddress']); // Match the IP address
            $this->db->update('tblenrichment_data', $update_data);
        }
        return true;
    }

    // Update request status in tblrequest_enrichment
    public function updateRequestStatus($request_id, $status, $enrichment_data_count, $datazapp_cost = '', $melissa_cost = '')
    {
        // Update Query
        $this->db->where('id', $request_id);
        $this->db->update('tblrequest_enrichment', [
            'status' => $status,
            'datazapp_cost' => $datazapp_cost,
            'melissa_cost' => $melissa_cost,
            'enrichment_data' => $enrichment_data_count
        ]);

        // Check if the update was successful
      
            return true;
    }
    public function getRequest($user_id)
    {
        // Replace with actual query to fetch data for the user
        $this->db->select('*');
        $this->db->from('tblrequest_enrichment'); // Assuming the data you want to fetch is in this table
        $this->db->where('user_id_fk', $user_id);
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->result_array(); // Return the data for the user
        }
        return false; // No data found
    }



    public function getEnrichmentId($user_id, $request_id)
    {
        // Query to get the enrichment ID from tblrequest_enrichment based on the provided request_id
        $this->db->select('id');
        $this->db->from('tblrequest_enrichment');
        $this->db->where('request_id', $request_id);
        $this->db->where('user_id_fk', $user_id);
        $query = $this->db->get();

        // Return the Enrichment ID if found
        if ($query->num_rows() > 0) {
            return $query->row()->id;
        }
        return false; // No matching request_id found
    }

    public function getRequestData($enrichment_id)
    {
        // Query to get related data based on enrichment_id
        $this->db->select('*');
        $this->db->from('tblenrichment_data'); // or any related table as needed
        $this->db->where('request_id_fk', $enrichment_id);
        $query = $this->db->get();

        // Return the data if found
        if ($query->num_rows() > 0) {
            return $query->result_array();
        }
        return false; // No data found for this enrichment_id
    }
}
