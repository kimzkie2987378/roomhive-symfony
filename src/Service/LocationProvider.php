<?php

namespace App\Service;

final class LocationProvider
{
    /**
     * province => [towns / municipalities / cities, ...]
     *
     * Sorted A–Z at render time by the JS component — the order
     * here doesn't matter. Append more municipalities freely.
     */
    private const PROVINCES = [
        'Abra' => ['Bangued', 'Boliney', 'Bucay', 'Bucloc', 'Daguioman', 'Danglas', 'Dolores', 'La Paz', 'Lacub', 'Lagangilang', 'Lagayan', 'Langiden', 'Licuan-Baay', 'Luba', 'Malibcong', 'Manabo', 'Peñarrubia', 'Pidigan', 'Pilar', 'Sallapadan', 'San Isidro', 'San Juan', 'San Quintin', 'Tineg', 'Tayum', 'Villaviciosa'],

        'Agusan del Norte' => ['Buenavista', 'Butuan City', 'Cabadbaran City', 'Carmen', 'Jabonga', 'Kitcharao', 'Las Nieves', 'Magallanes', 'Nasipit', 'Remedios T. Romualdez', 'Santiago', 'Tubay'],

        'Agusan del Sur' => ['Bayugan City', 'Bunawan', 'Esperanza', 'La Paz', 'Loreto', 'Prosperidad', 'Rosario', 'San Francisco', 'San Luis', 'Santa Josefa', 'Sibagat', 'Talacogon', 'Trento', 'Veruela'],

        'Aklan' => ['Altavas', 'Balete', 'Banga', 'Batan', 'Buruanga', 'Ibajay', 'Kalibo', 'Lezo', 'Libacao', 'Madalag', 'Makato', 'Malay (Boracay)', 'Malinao', 'Nabas', 'New Washington', 'Numancia', 'Tangalan'],

        'Albay' => ['Bacacay', 'Camalig', 'Daraga', 'Guinobatan', 'Jovellar', 'Legazpi City', 'Libon', 'Ligao City', 'Malilipot', 'Malinao', 'Manito', 'Oas', 'Pio Duran', 'Polangui', 'Rapu-Rapu', 'Santo Domingo', 'Tabaco City', 'Tiwi'],

        'Antique' => ['Anini-y', 'Barbaza', 'Belison', 'Bugasong', 'Caluya', 'Culasi', 'Hamtic', 'Laua-an', 'Libertad', 'Pandan', 'Patnongon', 'San Jose de Buenavista', 'San Remigio', 'Sebaste', 'Sibalom', 'Tibiao', 'Tobias Fornier', 'Valderrama'],

        'Apayao' => ['Calanasan', 'Conner', 'Flora', 'Kabugao', 'Luna', 'Pudtol', 'Santa Marcela'],

        'Aurora' => ['Baler', 'Casiguran', 'Dilasag', 'Dinalungan', 'Dipaculao', 'Maria Aurora', 'San Luis'],

        'Basilan' => ['Isabela City', 'Lamitan City', 'Lantawan', 'Maluso', 'Sumisip', 'Tipo-Tipo', 'Tuburan'],

        'Bataan' => ['Abucay', 'Bagac', 'Balanga City', 'Dinalupihan', 'Hermosa', 'Limay', 'Mariveles', 'Morong', 'Orani', 'Orion', 'Pilar', 'Samal'],

        'Batanes' => ['Basco', 'Itbayat', 'Ivana', 'Mahatao', 'Sabtang', 'Uyugan'],

        'Batangas' => ['Alangilan', 'Batangas City', 'Bauan', 'Calaca', 'Calatagan', 'Cuenca', 'Ibaan', 'Laurel', 'Lemery', 'Lian', 'Lipa City', 'Lobo', 'Mabini', 'Malvar', 'Mataasnakahoy', 'Nasugbu', 'Padre Garcia', 'Rosario', 'San Jose', 'San Juan', 'San Luis', 'San Nicolas', 'San Pascual', 'Santa Teresita', 'Tanauan City', 'Santo Tomas', 'Taysan', 'Tingloy', 'Tuy'],

        'Benguet' => ['Atok', 'Bakun', 'Bokod', 'Buguias', 'Itogon', 'Kabayan', 'Kapangan', 'Kibungan', 'La Trinidad', 'Mankayan', 'Sablan', 'Tuba', 'Tublay'],

        'Biliran' => ['Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Culaba', 'Kawayan', 'Maripipi', 'Naval'],

        'Bohol' => ['Alburquerque', 'Anda', 'Antequera', 'Baclayon', 'Balilihan', 'Batuan', 'Bien Unido', 'Bilar', 'Buenavista', 'Calape', 'Candijay', 'Carmen', 'Catigbian', 'Clarin', 'Corella', 'Cortes', 'Dagohoy', 'Danao', 'Dauis', 'Dimiao', 'Duero', 'Garcia Hernandez', 'Getafe', 'Guindulman', 'Inabanga', 'Jagna', 'Lila', 'Loay', 'Loboc', 'Loon', 'Mabini', 'Maribojoc', 'Panglao', 'Pilar', 'Pres. Carlos P. Garcia', 'Sagbayan', 'San Isidro', 'San Miguel', 'Sevilla', 'Sierra Bullones', 'Sikatuna', 'Tagbilaran City', 'Talibon', 'Trinidad', 'Tubigon', 'Ubay', 'Valencia'],

        'Bukidnon' => ['Baungon', 'Cabanglasan', 'Damulog', 'Dangcagan', 'Don Carlos', 'Impasugong', 'Kadingilan', 'Kalilangan', 'Kibawe', 'Kitaotao', 'Lantapan', 'Libona', 'Malitbog', 'Manolo Fortich', 'Maramag', 'Pangantucan', 'Quezon', 'San Fernando', 'Sumilao', 'Talakag', 'Malaybalay City', 'Valencia City'],

        'Bulacan' => ['Angat', 'Balagtas', 'Baliuag', 'Bocaue', 'Bulakan', 'Bustos', 'Calumpit', 'Doña Remedios Trinidad', 'Guiguinto', 'Hagonoy', 'Malolos City', 'Marilao', 'Meycauayan City', 'Norzagaray', 'Obando', 'Pandi', 'Paombong', 'Plaridel', 'Pulilan', 'San Ildefonso', 'San Jose del Monte City', 'San Miguel', 'San Rafael', 'Santa Maria'],

        'Cagayan' => ['Abulug', 'Alcala', 'Allacapan', 'Amulung', 'Aparri', 'Baggao', 'Ballesteros', 'Buguey', 'Calayan', 'Camalaniugan', 'Claveria', 'Enrile', 'Gattaran', 'Gonzaga', 'Iguig', 'Lal-lo', 'Lasam', 'Pamplona', 'Peñablanca', 'Piat', 'Rizal', 'Sanchez-Mira', 'Santa Ana', 'Santa Praxedes', 'Santa Teresita', 'Santo Niño', 'Solana', 'Tuao', 'Tuguegarao City'],

        'Camarines Norte' => ['Basud', 'Capalonga', 'Daet', 'Jose Panganiban', 'Labo', 'Mercedes', 'Paracale', 'Santa Elena', 'Talisay', 'Vinzons'],

        'Camarines Sur' => ['Baao', 'Balatan', 'Bato', 'Bombon', 'Buhi', 'Bula', 'Cabusao', 'Calabanga', 'Camaligan', 'Canaman', 'Caramoan', 'Del Gallego', 'Gainza', 'Garchitorena', 'Goa', 'Iriga City', 'Lagonoy', 'Libmanan', 'Lupi', 'Milaor', 'Minalabac', 'Nabua', 'Naga City', 'Ocampo', 'Pamplona', 'Pasacao', 'Pili', 'Presentacion', 'Ragay', 'Sagñay', 'San Fernando', 'San Jose', 'Sipocot', 'Siruma', 'Tigaon', 'Tinambac'],

        'Camiguin' => ['Catarman', 'Guinsiliban', 'Mahinog', 'Mambajao', 'Sagay'],

        'Capiz' => ['Cuartero', 'Dao', 'Dumalag', 'Dumarao', 'Ivisan', 'Jamindan', 'Maayon', 'Mambusao', 'Panay', 'Panitan', 'Pilar', 'Pontevedra', 'President Roxas', 'Roxas City', 'Sapian', 'Sigma', 'Tapaz'],

        'Catanduanes' => ['Bagamanoc', 'Baras', 'Bato', 'Caramoran', 'Gigmoto', 'Pandan', 'Panganiban', 'San Andres', 'San Miguel', 'Viga', 'Virac'],

        'Cavite' => ['Alfonso', 'Amadeo', 'Bacoor City', 'Carmona', 'Cavite City', 'Dasmariñas City', 'General Mariano Alvarez', 'General Trias City', 'Imus City', 'Indang', 'Kawit', 'Magallanes', 'Maragondon', 'Mendez', 'Naic', 'Noveleta', 'Rosario', 'Silang', 'Tagaytay City', 'Tanza', 'Ternate', 'Trece Martires City'],

        'Cebu' => ['Alcantara', 'Alcoy', 'Alegria', 'Aloguinsan', 'Argao', 'Asturias', 'Badian', 'Balamban', 'Bantayan', 'Barili', 'Bogo City', 'Boljoon', 'Borbon', 'Carcar City', 'Carmen', 'Catmon', 'Cebu City', 'Compostela', 'Consolacion', 'Cordova', 'Daanbantayan', 'Dalaguete', 'Danao City', 'Dumanjug', 'Ginatilan', 'Lapu-Lapu City', 'Liloan', 'Madridejos', 'Malabuyoc', 'Mandaue City', 'Medellin', 'Minglanilla', 'Moalboal', 'Naga City', 'Oslob', 'Pilar', 'Pinamungahan', 'Poro', 'Ronda', 'Samboan', 'San Fernando', 'San Francisco', 'San Remigio', 'Santa Fe', 'Santander', 'Sibonga', 'Sogod', 'Tabogon', 'Tabuelan', 'Talisay City', 'Toledo City', 'Tuburan', 'Tudela'],

        'Cotabato' => ['Alamada', 'Aleosan', 'Antipas', 'Arakan', 'Banisilan', 'Carmen', 'Kabacan', 'Kidapawan City', 'Libungan', 'M\'lang', 'Magpet', 'Makilala', 'Matalam', 'Midsayap', 'Pigcawayan', 'Pikit', 'President Roxas', 'Tulunan'],

        'Davao de Oro' => ['Compostela', 'Laak', 'Mabini', 'Maco', 'Maragusan', 'Mawab', 'Monkayo', 'Montevista', 'Nabunturan', 'New Bataan', 'Pantukan'],

        'Davao del Norte' => ['Asuncion', 'Braulio E. Dujali', 'Carmen', 'Kapalong', 'New Corella', 'Panabo City', 'Samal City', 'San Isidro', 'Santo Tomas', 'Tagum City', 'Talaingod'],

        'Davao del Sur' => ['Bansalan', 'Digos City', 'Hagonoy', 'Kiblawan', 'Magsaysay', 'Malalag', 'Matanao', 'Padada', 'Santa Cruz', 'Sulop'],

        'Davao City' => ['Baguio District', 'Buhangin', 'Bunawan', 'Calinan', 'Catalunan Grande', 'Matina', 'Mintal', 'Panacan', 'Sasa', 'Talomo', 'Toril', 'Tugbok'],

        'Davao Occidental' => ['Don Marcelino', 'Jose Abad Santos', 'Malita', 'Santa Maria', 'Sarangani'],

        'Davao Oriental' => ['Baganga', 'Banaybanay', 'Boston', 'Caraga', 'Cateel', 'Governor Generoso', 'Lupon', 'Manay', 'Mati City', 'San Isidro', 'Tarragona'],

        'Dinagat Islands' => ['Basilisa', 'Cagdianao', 'Dinagat', 'Libjo', 'Loreto', 'San Jose', 'Tubajon'],

        'Eastern Samar' => ['Arteche', 'Balangiga', 'Balangkayan', 'Borongan City', 'Can-avid', 'Dolores', 'General MacArthur', 'Giporlos', 'Guiuan', 'Hernani', 'Jipapad', 'Llorente', 'Maslog', 'Maydolong', 'Mercedes', 'Oras', 'Quinapondan', 'Salcedo', 'San Julian', 'San Policarpo', 'Sulat', 'Taft'],

        'Guimaras' => ['Buenavista', 'Jordan', 'Nueva Valencia', 'San Lorenzo', 'Sibunag'],

        'Ifugao' => ['Aguinaldo', 'Alfonso Lista', 'Asipulo', 'Banaue', 'Hingyon', 'Hungduan', 'Kiangan', 'Lagawe', 'Lamut', 'Mayoyao', 'Tinoc'],

        'Ilocos Norte' => ['Adams', 'Bacarra', 'Badoc', 'Banna', 'Bangui', 'Batac City', 'Burgos', 'Carasi', 'Currimao', 'Dingras', 'Dumalneg', 'Laoag City', 'Marcos', 'Nueva Era', 'Pagudpud', 'Paoay', 'Pasuquin', 'Piddig', 'Pinili', 'San Nicolas', 'Sarrat', 'Solsona', 'Vintar'],

        'Ilocos Sur' => ['Alilem', 'Banayoyo', 'Bantay', 'Burgos', 'Cabugao', 'Candon City', 'Caoayan', 'Cervantes', 'Galimuyod', 'Gregorio Del Pilar', 'Lidlidda', 'Magsingal', 'Nagbukel', 'Narvacan', 'Quirino', 'Salcedo', 'San Emilio', 'San Esteban', 'San Ildefonso', 'San Juan', 'San Vicente', 'Santa', 'Santa Catalina', 'Santa Cruz', 'Santa Lucia', 'Santa Maria', 'Santiago', 'Santo Domingo', 'Sigay', 'Sinait', 'Sugpon', 'Suyo', 'Tagudin', 'Vigan City'],

        'Iloilo' => ['Ajuy', 'Alimodian', 'Anilao', 'Badiangan', 'Balasan', 'Banate', 'Barotac Nuevo', 'Barotac Viejo', 'Batad', 'Cabatuan', 'Calinog', 'Carles', 'Concepcion', 'Dingle', 'Dueñas', 'Dumangas', 'Estancia', 'Guimbal', 'Igbaras', 'Iloilo City', 'Janiuay', 'Lambunao', 'Leganes', 'Lemery', 'Leon', 'Maasin', 'Miagao', 'Mina', 'New Lucena', 'Oton', 'Passi City', 'Pavia', 'Pototan', 'San Dionisio', 'San Enrique', 'San Joaquin', 'San Miguel', 'San Rafael', 'Santa Barbara', 'Sara', 'Tigbauan', 'Tubungan', 'Zarraga'],

        'Isabela' => ['Alicia', 'Angadanan', 'Aurora', 'Benito Soliven', 'Burgos', 'Cabagan', 'Cabatuan', 'Cauayan City', 'Cordon', 'Delfin Albano', 'Dinapigue', 'Divilacan', 'Echague', 'Gamu', 'Ilagan City', 'Jones', 'Maconacon', 'Mallig', 'Naguilian', 'Palanan', 'Quezon', 'Quirino', 'Ramon', 'Reina Mercedes', 'Roxas', 'San Agustin', 'San Guillermo', 'San Isidro', 'San Manuel', 'San Mariano', 'San Mateo', 'San Pablo', 'Santa Maria', 'Santiago City', 'Santo Tomas', 'Tumauini'],

        'Kalinga' => ['Balbalan', 'Lubuagan', 'Pasil', 'Pinukpuk', 'Rizal', 'Tabuk City', 'Tanudan', 'Tinglayan'],

        'La Union' => ['Agoo', 'Aringay', 'Bacnotan', 'Bagulin', 'Balaoan', 'Bangar', 'Bauang', 'Burgos', 'Caba', 'Luna', 'Naguilian', 'Pugo', 'Rosario', 'San Fernando City', 'San Gabriel', 'San Juan', 'Santo Tomas', 'Santol', 'Sudipen', 'Tubao'],

        'Laguna' => ['Alaminos', 'Bay', 'Biñan City', 'Cabuyao City', 'Calamba City', 'Calauan', 'Cavinti', 'Famy', 'Kalayaan', 'Liliw', 'Los Baños', 'Luisiana', 'Lumban', 'Mabitac', 'Magdalena', 'Majayjay', 'Nagcarlan', 'Paete', 'Pagsanjan', 'Pakil', 'Pangil', 'Pila', 'Rizal', 'San Pablo City', 'Santa Cruz', 'Santa Maria', 'Santa Rosa City', 'Siniloan', 'Victoria'],

        'Lanao del Norte' => ['Bacolod', 'Balo-i', 'Baroy', 'Iligan City', 'Kapatagan', 'Kauswagan', 'Kolambugan', 'Lala', 'Linamon', 'Maigo', 'Magsaysay', 'Matungao', 'Munai', 'Nunungan', 'Pantar', 'Poona Piagapo', 'Salvador', 'Sapad', 'Tagoloan', 'Tangcal', 'Tubod'],

        'Lanao del Sur' => ['Bacolod-Kalawi', 'Balabagan', 'Balindong', 'Bayang', 'Binidayan', 'Buenavista', 'Butig', 'Calanogas', 'Ditsaan-Ramain', 'Ganassi', 'Kapai', 'Kapatagan', 'Lumba-Bayabao', 'Lumbatan', 'Lumbayanague', 'Madalum', 'Madamba', 'Maguing', 'Malabang', 'Marantao', 'Marawi City', 'Marogong', 'Masiu', 'Mulondo', 'Pagayawan', 'Piagapo', 'Poona Bayabao', 'Pualas', 'Saguiaran', 'Sultan Dumalondong', 'Tamparan', 'Taraka', 'Tugaya', 'Wao'],

        'Leyte' => ['Abuyog', 'Alangalang', 'Albuera', 'Babatngon', 'Barugo', 'Bato', 'Baybay City', 'Burauen', 'Calubian', 'Capoocan', 'Carigara', 'Dagami', 'Dulag', 'Hilongos', 'Hindang', 'Inopacan', 'Isabel', 'Jaro', 'Javier', 'Julita', 'Kananga', 'La Paz', 'Leyte', 'MacArthur', 'Mahaplag', 'Matag-ob', 'Matalom', 'Mayorga', 'Merida', 'Ormoc City', 'Palo', 'Palompon', 'Pastrana', 'San Miguel', 'Santa Fe', 'Tacloban City', 'Tabango', 'Tabontabon', 'Tanauan', 'Tolosa', 'Tunga', 'Villaba'],

        'Maguindanao' => ['Barira', 'Buldon', 'Datu Abdullah Sangki', 'Datu Anggal Midtimbang', 'Datu Blah T. Sinsuat', 'Datu Hoffer Ampatuan', 'Datu Montawal', 'Datu Odin Sinsuat', 'Datu Paglas', 'Datu Piang', 'Datu Salibo', 'Datu Saudi-Ampatuan', 'Datu Unsay', 'General Salipada K. Pendatun', 'Guindulungan', 'Kabuntalan', 'Mamasapano', 'Mangudadatu', 'Pagalungan', 'Paglat', 'Parang', 'Rajah Buayan', 'Shariff Aguak', 'Shariff Saydona Mustapha', 'South Upi', 'Sultan Barongis', 'Sultan Kudarat', 'Sultan Mastura', 'Talayan', 'Upi'],

        'Cotabato City' => ['Cotabato City'],

        'Marinduque' => ['Boac', 'Buenavista', 'Gasan', 'Mogpog', 'Santa Cruz', 'Torrijos'],

        'Masbate' => ['Aroroy', 'Baleno', 'Balud', 'Batuan', 'Cataingan', 'Cawayan', 'Claveria', 'Dimasalang', 'Esperanza', 'Masbate City', 'Mandaon', 'Milagros', 'Mobo', 'Monreal', 'Palanas', 'Pio V. Corpuz', 'Placer', 'San Fernando', 'San Jacinto', 'San Pascual'],

        'Misamis Occidental' => ['Aloran', 'Baliangao', 'Bonifacio', 'Calamba', 'Clarin', 'Concepcion', 'Don Victoriano Chiongbian', 'Jimenez', 'Lopez Jaena', 'Oroquieta City', 'Ozamiz City', 'Panaon', 'Plaridel', 'Sapang Dalaga', 'Sinacaban', 'Tangub City', 'Tudela'],

        'Misamis Oriental' => ['Alubijid', 'Balingasag', 'Balingoan', 'Binuangan', 'Cagayan de Oro City', 'Claveria', 'El Salvador City', 'Gitagum', 'Gingoog City', 'Initao', 'Jasaan', 'Kinoguitan', 'Laguindingan', 'Libertad', 'Lugait', 'Manticao', 'Medellin', 'Misamis', 'Naawan', 'Opol', 'Salay', 'Sugbongcogon', 'Tagoloan', 'Talisayan', 'Villanueva'],

        'Mountain Province' => ['Barlig', 'Bauko', 'Besao', 'Bontoc', 'Natonin', 'Paracelis', 'Sabangan', 'Sadanga', 'Sagada', 'Tadian'],

        'Metro Manila' => ['Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig', 'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela'],

        'Negros Occidental' => ['Bacolod City', 'Bago City', 'Binalbagan', 'Cadiz City', 'Calatrava', 'Candoni', 'Cauayan', 'E.B. Magalona', 'Escalante City', 'Himamaylan City', 'Hinigaran', 'Hinoba-an', 'Ilog', 'Isabela', 'Kabankalan City', 'La Carlota City', 'La Castellana', 'Manapla', 'Moises Padilla', 'Murcia', 'Pontevedra', 'Pulupandan', 'Sagay City', 'Salvador Benedicto', 'San Carlos City', 'San Enrique', 'Silay City', 'Sipalay City', 'Talisay City', 'Toboso', 'Valladolid', 'Victorias City'],

        'Negros Oriental' => ['Amlan', 'Ayungon', 'Bacong', 'Bais City', 'Basay', 'Bayawan City', 'Bindoy', 'Canlaon City', 'Dauin', 'Dumaguete City', 'Guihulngan City', 'Jimalalud', 'La Libertad', 'Mabinay', 'Manjuyod', 'Pamplona', 'San Jose', 'Santa Catalina', 'Siaton', 'Sibulan', 'Tanjay City', 'Tayasan', 'Valencia', 'Vallehermoso', 'Zamboanguita'],

        'Northern Samar' => ['Allen', 'Biri', 'Bobon', 'Capul', 'Catarman', 'Catubig', 'Gamay', 'Laoang', 'Lapinig', 'Las Navas', 'Lavezares', 'Mapanas', 'Mondragon', 'Palapag', 'Pambujan', 'Rosario', 'San Antonio', 'San Isidro', 'San Jose', 'San Roque', 'San Vicente', 'Silvino Lobos', 'Victoria'],

        'Nueva Ecija' => ['Aliaga', 'Bongabon', 'Cabanatuan City', 'Carranglan', 'Cuyapo', 'Gapan City', 'General Mamerto Natividad', 'General Tinio', 'Guimba', 'Jaen', 'Laur', 'Licab', 'Llanera', 'Lupao', 'Nampicuan', 'Palayan City', 'Pantabangan', 'Peñaranda', 'Quezon', 'Rizal', 'San Antonio', 'San Isidro', 'San Leonardo', 'San Jose City', 'Santa Rosa', 'Santo Domingo', 'Talavera', 'Talugtug', 'Zaragoza'],

        'Nueva Vizcaya' => ['Ambaguio', 'Aritao', 'Bagabag', 'Bambang', 'Bayombong', 'Diadi', 'Dupax del Norte', 'Dupax del Sur', 'Kasibu', 'Kayapa', 'Quezon', 'Santa Fe', 'Solano', 'Villaverde'],

        'Occidental Mindoro' => ['Abra de Ilog', 'Calintaan', 'Looc', 'Magsaysay', 'Mamburao', 'Paluan', 'Rizal', 'Sablayan', 'San Jose', 'Santa Cruz'],

        'Oriental Mindoro' => ['Baco', 'Bansud', 'Bongabong', 'Bulalacao', 'Calapan City', 'Gloria', 'Mansalay', 'Naujan', 'Pinamalayan', 'Pola', 'Puerto Galera', 'Roxas', 'San Teodoro', 'Socorro', 'Victoria'],

        'Palawan' => ['Aborlan', 'Agutaya', 'Araceli', 'Balabac', 'Bataraza', 'Brooke\'s Point', 'Busuanga', 'Cagayancillo', 'Coron', 'Culion', 'Cuyo', 'Dumaran', 'El Nido', 'Kalayaan', 'Linapacan', 'Magsaysay', 'Narra', 'Puerto Princesa City', 'Quezon', 'Rizal', 'Roxas', 'San Vicente', 'Sofronio Española', 'Taytay'],

        'Pampanga' => ['Angeles City', 'Apalit', 'Arayat', 'Bacolor', 'Candaba', 'Floridablanca', 'Guagua', 'Lubao', 'Mabalacat City', 'Macabebe', 'Magalang', 'Masantol', 'Mexico', 'Minalin', 'Porac', 'San Fernando City', 'San Luis', 'San Simon', 'Santa Ana', 'Santa Rita', 'Santo Tomas', 'Sasmuan'],

        'Pangasinan' => ['Agno', 'Alaminos City', 'Anda', 'Asingan', 'Balungao', 'Bani', 'Basista', 'Bayambang', 'Bautista', 'Binmaley', 'Bolinao', 'Bugallon', 'Burgos', 'Calasiao', 'Dagupan City', 'Dasol', 'Infanta', 'Labrador', 'Laoac', 'Lingayen', 'Mabini', 'Malasiqui', 'Manaoag', 'Mangaldan', 'Mangatarem', 'Mapandan', 'Natividad', 'Pozorrubio', 'Rosales', 'San Carlos City', 'San Fabian', 'San Jacinto', 'San Manuel', 'San Nicolas', 'San Quintin', 'Sison', 'Sta. Barbara', 'Sta. Maria', 'Sto. Tomas', 'Tayug', 'Umingan', 'Urdaneta City', 'Villasis'],

        'Quezon' => ['Agdangan', 'Alabat', 'Atimonan', 'Buenavista', 'Burdeos', 'Calauag', 'Candelaria', 'Catanauan', 'Dolores', 'General Luna', 'General Nakar', 'Guinayangan', 'Gumaca', 'Infanta', 'Jomalig', 'Lopez', 'Lucban', 'Lucena City', 'Macalelon', 'Mauban', 'Mulanay', 'Padre Burgos', 'Pagbilao', 'Panukulan', 'Patnanungan', 'Perez', 'Pitogo', 'Plaridel', 'Polillo', 'Quezon', 'Real', 'Sampaloc', 'San Andres', 'San Antonio', 'San Francisco', 'San Narciso', 'Sariaya', 'Tagkawayan', 'Tayabas City', 'Tiaong', 'Unisan'],

        'Quirino' => ['Aglipay', 'Cabarroguis', 'Diffun', 'Maddela', 'Nagtipunan', 'Saguday'],

        'Rizal' => ['Angono', 'Antipolo City', 'Baras', 'Binangonan', 'Cainta', 'Cardona', 'Jalajala', 'Morong', 'Pililla', 'Rodriguez', 'San Mateo', 'Tanay', 'Taytay', 'Teresa'],

        'Romblon' => ['Alcantara', 'Banton', 'Cajidiocan', 'Calatrava', 'Concepcion', 'Corcuera', 'Ferrol', 'Looc', 'Magdiwang', 'Odiongan', 'Romblon', 'San Andres', 'San Agustin', 'San Fernando', 'San Jose', 'Santa Fe', 'Santa Maria'],

        'Samar' => ['Almagro', 'Basey', 'Calbayog City', 'Calbiga', 'Catbalogan City', 'Daram', 'Gandara', 'Hinabangan', 'Jiabong', 'Marabut', 'Matuguinao', 'Motiong', 'Pagsanghan', 'Paranas', 'Pinabacdao', 'San Jorge', 'San Sebastian', 'Santa Rita', 'Santo Niño', 'Tagapul-an', 'Talalora', 'Tarangnan', 'Villareal', 'Zumarraga'],

        'Sarangani' => ['Alabel', 'Glan', 'Kiamba', 'Maasim', 'Maitum', 'Malapatan', 'Malungon'],

        'Siquijor' => ['Enrique Villanueva', 'Larena', 'Lazi', 'Maria', 'San Juan', 'Siquijor'],

        'Sorsogon' => ['Barcelona', 'Bulan', 'Bulusan', 'Casiguran', 'Castilla', 'Donsol', 'Gubat', 'Irosin', 'Juban', 'Magallanes', 'Matnog', 'Pilar', 'Prieto Diaz', 'Santa Magdalena', 'Sorsogon City'],

        'South Cotabato' => ['Banga', 'Koronadal City', 'Lake Sebu', 'Norala', 'Polomolok', 'Santo Niño', 'Surallah', 'T\'boli', 'Tampakan', 'Tantangan', 'Tupi'],

        'Southern Leyte' => ['Anahawan', 'Bontoc', 'Hinunangan', 'Hinundayan', 'Liloan', 'Limasawa', 'Macrohon', 'Maasin City', 'Malitbog', 'Padre Burgos', 'Pintuyan', 'San Francisco', 'San Juan', 'San Ricardo', 'Silago', 'Sogod', 'St. Bernard', 'Tomas Oppus'],

        'Sultan Kudarat' => ['Bagumbayan', 'Columbio', 'Esperanza', 'Isulan', 'Kalamansig', 'Lambayong', 'Lebak', 'Lutayan', 'Palimbang', 'President Quirino', 'Senator Ninoy Aquino', 'Tacurong City'],

        'Sulu' => ['Indanan', 'Jolo', 'Kalingalan Caluang', 'Lugus', 'Luuk', 'Maimbung', 'Pandami', 'Panglima Estino', 'Pangutaran', 'Parang', 'Pata', 'Patikul', 'Siasi', 'Talipao', 'Tapul', 'Tongkil'],

        'Surigao del Norte' => ['Alegria', 'Bacuag', 'Burgos', 'Claver', 'Dapa', 'Del Carmen', 'General Luna', 'Gigaquit', 'Mainit', 'Malimono', 'Pilar', 'Placer', 'San Benito', 'San Francisco', 'San Isidro', 'Sison', 'Socorro', 'Surigao City', 'Tagana-an', 'Tubod'],

        'Surigao del Sur' => ['Barobo', 'Bayabas', 'Bislig City', 'Cagwait', 'Cantilan', 'Carmen', 'Cortes', 'Hinatuan', 'Lanuza', 'Lianga', 'Lingig', 'Madrid', 'Marihatag', 'San Agustin', 'San Miguel', 'Tagbina', 'Tago', 'Tandag City'],

        'Tarlac' => ['Anao', 'Bamban', 'Camiling', 'Capas', 'Concepcion', 'Gerona', 'La Paz', 'Mayantoc', 'Moncada', 'Paniqui', 'Pura', 'Ramos', 'San Clemente', 'San Jose', 'San Manuel', 'Santa Ignacia', 'Tarlac City', 'Victoria'],

        'Tawi-Tawi' => ['Bongao', 'Languyan', 'Mapun', 'Panglima Sugala', 'Sapa-Sapa', 'Sibutu', 'Simunul', 'Sitangkai', 'South Ubian', 'Tandubas', 'Turtle Islands'],

        'Zambales' => ['Botolan', 'Cabangan', 'Candelaria', 'Castillejos', 'Iba', 'Masinloc', 'Olongapo City', 'Palauig', 'San Antonio', 'San Felipe', 'San Marcelino', 'San Narciso', 'Santa Cruz'],

        'Zamboanga del Norte' => ['Baliguian', 'Dapitan City', 'Dipolog City', 'Godod', 'Gutalac', 'Jose Dalman', 'Kalawit', 'Katipunan', 'La Libertad', 'Labason', 'Liloy', 'Manukan', 'Mutia', 'Piñan', 'Polanco', 'Pres. Manuel A. Roxas', 'Rizal', 'Salug', 'Sergio Osmeña Sr.', 'Siayan', 'Sibuco', 'Sibutad', 'Sirawai', 'Sindangan', 'Siocon', 'Tampilisan'],

        'Zamboanga del Sur' => ['Aurora', 'Bayog', 'Dimataling', 'Dinas', 'Dumalinao', 'Dumingag', 'Guipos', 'Josefina', 'Kumalarang', 'Lakewood', 'Lapuyan', 'Mahayag', 'Margosatubig', 'Midsalip', 'Molave', 'Pagadian City', 'Pitogo', 'Ramon Magsaysay', 'San Miguel', 'San Pablo', 'Sominot', 'Tabina', 'Tambulig', 'Tigbao', 'Tukuran', 'Vincenzo A. Sagun'],

        'Zamboanga Sibugay' => ['Alicia', 'Buug', 'Diplahan', 'Imelda', 'Ipil', 'Kabasalan', 'Mabuhay', 'Malangas', 'Naga', 'Olutanga', 'Payao', 'Roseller Lim', 'Siay', 'Talusan', 'Titay', 'Tungawan'],
    ];

    /** Full dataset: province => towns */
    public function all(): array
    {
        return self::PROVINCES;
    }

    /** Sorted province names A–Z */
    public function provinces(): array
    {
        $provinces = array_keys(self::PROVINCES);
        sort($provinces, SORT_NATURAL | SORT_FLAG_CASE);

        return $provinces;
    }

    /** Sorted towns of one province (empty array if unknown) */
    public function towns(string $province): array
    {
        $towns = self::PROVINCES[$province] ?? [];
        sort($towns, SORT_NATURAL | SORT_FLAG_CASE);

        return $towns;
    }
}
