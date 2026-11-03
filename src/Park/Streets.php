<?php
declare(strict_types=1);

namespace Bins\Park;

/**
 * The streets of The Park that residents can choose from, each with one or two
 * sample addresses in the council's bin system (ReCollect place IDs). The daily
 * sync reads each street's collection calendar from its first sample address,
 * falling back to the second.
 *
 * Surveyed on 3 November 2026: every numbered address the council's system lists on
 * these streets (about 1,200) was checked, and no street is split across collection
 * days. Almost all of The Park is on the Wednesday round; Harlaxton Drive, Clinton
 * Terrace and the Park side of Derby Road are on Thursday; Maria Court has a weekly
 * Monday general waste collection only. Garden waste is on Friday throughout.
 * Flats with shared bins, or no council collection, are the only exceptions.
 *
 * Streets with only named houses (Tunnel Road, Rocky Mount, Sun Drive and some mews)
 * have no numbered addresses to sample; residents there are asked to get in touch.
 */
final class Streets
{
    /** @var array<string, array{0: string, 1: list<string>}> slug => [name, sample place IDs] */
    private const STREETS = [
        'albury-square' => ['Albury Square', ['7AF75AAE-DBFE-11EE-A222-A7388EA0F4B8', '7AF9131C-DBFE-11EE-88B5-A7388EA0F4B8']], // 1 Albury Square, NG7 1AB; 2 Albury Square, NG7 1AB
        'alexander-road' => ['Alexander Road', ['9DAEEAEA-DBFD-11EE-B7E9-A7388EA0F4B8', '9DB0F470-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Peveril Mews, Alexander Road, NG7 1FH; 2 Peveril Mews, Alexander Road, NG7 1FH
        'barrack-lane' => ['Barrack Lane', ['A428FD7E-DBFE-11EE-BD08-A7388EA0F4B8', 'A4369682-DBFE-11EE-ADD5-A7388EA0F4B8']], // 1 Barrack Lane, NG7 1AN; Flat 1 Maitland House, 2 Barrack Lane, NG7 1AN
        'castle-grove' => ['Castle Grove', ['3C51F53E-DBFE-11EE-A692-A8388EA0F4B8', '3C53B8E2-DBFE-11EE-A692-A8388EA0F4B8']], // 1 Castle Grove, NG7 1DN; 2 Castle Grove, NG7 1DN
        'castle-mews' => ['Castle Mews', ['B8FC0A1C-DBFD-11EE-B7E9-A7388EA0F4B8', 'B8FD9D46-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Castle Mews, Lenton Avenue, NG7 1EA; 2 Castle Mews, Lenton Avenue, NG7 1EA
        'cavendish-crescent-north' => ['Cavendish Crescent North', ['3E6F90E2-DBFE-11EE-BB9C-A8388EA0F4B8', '3E718866-DBFE-11EE-A5F5-A8388EA0F4B8']], // 1 Cavendish Crescent North, NG7 1AY; 3 Cavendish Crescent North, NG7 1AY
        'cavendish-crescent-south' => ['Cavendish Crescent South', ['3E98A45A-DBFE-11EE-9717-A8388EA0F4B8', '3E9A8CF2-DBFE-11EE-9717-A8388EA0F4B8']], // Flat 1, 1 Cavendish Crescent South, NG7 1EN; Flat 2, 1 Cavendish Crescent South, NG7 1EN
        'cavendish-mews' => ['Cavendish Mews', ['C6202CD2-DBFD-11EE-B7E9-A7388EA0F4B8', 'C621E4C8-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Cavendish Mews, Tunnel Road, NG7 1BY; 2 Cavendish Mews, Tunnel Road, NG7 1BY
        'cavendish-place' => ['Cavendish Place', ['A017F0BA-DBFD-11EE-B7E9-A7388EA0F4B8', 'A019DB5A-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Cavendish Place, Cavendish Crescent South, NG7 1ED; 2 Cavendish Place, Cavendish Crescent South, NG7 1ED
        'cavendish-road-east' => ['Cavendish Road East', ['3EF4F066-DBFE-11EE-93A7-A8388EA0F4B8', '3EF6A316-DBFE-11EE-8411-A8388EA0F4B8']], // 20 Cavendish Road East, NG7 1BB; 22 Cavendish Road East, NG7 1BB
        'cavendish-road-west' => ['Cavendish Road West', ['3F127B9A-DBFE-11EE-943F-A8388EA0F4B8', '3F165706-DBFE-11EE-948D-A8388EA0F4B8']], // 3 Cavendish Road West, NG7 1EQ; 5 Cavendish Road West, NG7 1EQ
        'clare-valley' => ['Clare Valley', ['52EF4166-DBFE-11EE-96DA-A8388EA0F4B8', '52F1157C-DBFE-11EE-9A25-A8388EA0F4B8']], // 1 Clare Valley, NG7 1BU; 2 Clare Valley, NG7 1BU
        'clinton-mews' => ['Clinton Mews', ['C4FE14EA-DBFD-11EE-B7E9-A7388EA0F4B8']], // 2 Clinton Mews, Tattershall Drive, NG7 1HX
        'clinton-terrace' => ['Clinton Terrace', ['7271FDA4-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Clinton Terrace, Derby Road, NG7 1LY
        'clumber-crescent-north' => ['Clumber Crescent North', ['57621BEC-DBFE-11EE-B5C2-A8388EA0F4B8', '5763ECB0-DBFE-11EE-BAAA-A8388EA0F4B8']], // 1 Clumber Crescent North, NG7 1EY; 2 Clumber Crescent North, NG7 1EY
        'clumber-crescent-south' => ['Clumber Crescent South', ['578C9BD8-DBFE-11EE-B8AF-A8388EA0F4B8', '578E6120-DBFE-11EE-BD09-A8388EA0F4B8']], // 3 Clumber Crescent South, NG7 1EH; Park Hall, 5 Clumber Crescent South, NG7 1EH
        'clumber-road-east' => ['Clumber Road East', ['57A9A16A-DBFE-11EE-B2CD-A8388EA0F4B8', 'F0F72744-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Clumber Road East, NG7 1BD; Flat 2 Westwood House, 3 Clumber Road East, NG7 1BD
        'clumber-road-west' => ['Clumber Road West', ['57C12D62-DBFE-11EE-8C68-A8388EA0F4B8', '57C31208-DBFE-11EE-B3B3-A8388EA0F4B8']], // 5 Clumber Road West, NG7 1EF; 7 Clumber Road West, NG7 1EF
        'derby-road' => ['Derby Road', ['7B33E53C-DBFE-11EE-96FA-A8388EA0F4B8', '7B35D108-DBFE-11EE-9993-A8388EA0F4B8']], // 117 Derby Road, NG7 1LS; 119 Derby Road, NG7 1LS
        'derby-terrace' => ['Derby Terrace', ['B19AA512-DBFD-11EE-B7E9-A7388EA0F4B8']], // 4 Derby Terrace, Derby Road, NG7 1ND
        'duke-william-mount' => ['Duke William Mount', ['822C0F2C-DBFE-11EE-89D1-A8388EA0F4B8', '822DEAC2-DBFE-11EE-9D56-A8388EA0F4B8']], // 1 Duke William Mount, NG7 1BH; 2 Duke William Mount, NG7 1BH
        'fiennes-crescent' => ['Fiennes Crescent', ['AAD8E800-DBFE-11EE-AC03-A8388EA0F4B8', 'AADAAF6E-DBFE-11EE-8C11-A8388EA0F4B8']], // 1 Fiennes Crescent, NG7 1ER; 2 Fiennes Crescent, NG7 1ER
        'fishpond-drive' => ['Fishpond Drive', ['C6B4BE0E-DBFF-11EE-96DB-AC388EA0F4B8', 'C6B769B0-DBFF-11EE-96DB-AC388EA0F4B8']], // Flat 1, 2 Fishpond Drive, NG7 1DG; Flat 2, 2 Fishpond Drive, NG7 1DG
        'foxes-close' => ['Foxes Close', ['E5573A30-DBFF-11EE-96DB-AC388EA0F4B8', 'E558C3AA-DBFF-11EE-96DB-AC388EA0F4B8']], // 1 Foxes Close, NG7 1PG; 2 Foxes Close, NG7 1PG
        'friars-court' => ['Friars Court', ['31B69430-DBFF-11EE-ABAD-A8388EA0F4B8', '31B868BE-DBFF-11EE-9CF1-A8388EA0F4B8']], // 1 Friars Court, NG7 1EW; 2 Friars Court, NG7 1EW
        'hamilton-court' => ['Hamilton Court', ['ABC356AC-DBFD-11EE-B7E9-A7388EA0F4B8', 'ABC4EA30-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Hamilton Court, Hamilton Drive, NG7 1DF; 2 Hamilton Court, Hamilton Drive, NG7 1DF
        'hamilton-drive' => ['Hamilton Drive', ['DFE3B340-DBFE-11EE-B46A-A8388EA0F4B8', 'DFE5679E-DBFE-11EE-94A0-A8388EA0F4B8']], // 1 Hamilton Drive, NG7 1DF; 3 Hamilton Drive, NG7 1DF
        'hamilton-mews' => ['Hamilton Mews', ['A00EDB10-DBFD-11EE-B7E9-A7388EA0F4B8', 'A0109892-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Hamilton Mews, Cavendish Crescent North, NG7 1AY; 2 Hamilton Mews, Cavendish Crescent North, NG7 1AY
        'hardwick-grove' => ['Hardwick Grove', ['E2943AB0-DBFE-11EE-8BAD-A8388EA0F4B8', 'E29725F4-DBFE-11EE-B17F-A8388EA0F4B8']], // 1 Hardwick Grove, NG7 1LG; 2 Hardwick Grove, NG7 1LG
        'hardwick-road' => ['Hardwick Road', ['E2AEFD3C-DBFE-11EE-BFCE-A8388EA0F4B8', 'E2B09372-DBFE-11EE-9A68-A8388EA0F4B8']], // 1 Hardwick Road, NG7 1EP; 3 Hardwick Road, NG7 1EP
        'harlaxton-drive' => ['Harlaxton Drive', ['E36883F6-DBFE-11EE-885A-A8388EA0F4B8', 'E36B5360-DBFE-11EE-8FEB-A8388EA0F4B8']], // Byron Bungalow, 57 Harlaxton Drive, NG7 1JD; 58 Harlaxton Drive, NG7 1JB
        'hermitage-walk' => ['Hermitage Walk', ['FB3B0F76-DBFE-11EE-ADC0-A8388EA0F4B8', 'FB3CDB80-DBFE-11EE-AF0E-A8388EA0F4B8']], // 1 Hermitage Walk, NG7 1DR; 2 Hermitage Walk, NG7 1DR
        'holles-crescent' => ['Holles Crescent', ['EC3C81E8-DBFF-11EE-96DB-AC388EA0F4B8', 'EC3F1340-DBFF-11EE-96DB-AC388EA0F4B8']], // Flat 1, 1 Holles Crescent, NG7 1BZ; Flat 2, 1 Holles Crescent, NG7 1BZ
        'hope-drive' => ['Hope Drive', ['09342662-DBFF-11EE-AA37-A8388EA0F4B8', '0935F1B8-DBFF-11EE-9F93-A8388EA0F4B8']], // 2 Hope Drive, NG7 1DL; 5 Hope Drive, NG7 1DL
        'huntingdon-drive' => ['Huntingdon Drive', ['0F984D3A-DBFF-11EE-8147-A8388EA0F4B8', '0F99FA72-DBFF-11EE-9CA7-A8388EA0F4B8']], // 2 Huntingdon Drive, NG7 1BW; 3 Huntingdon Drive, NG7 1BW
        'kenilworth-court' => ['Kenilworth Court', ['A4308518-DBFD-11EE-B7E9-A7388EA0F4B8', 'A43222A6-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Kenilworth Court, Kenilworth Road, NG7 1FZ; 2 Kenilworth Court, Kenilworth Road, NG7 1FZ
        'kenilworth-road' => ['Kenilworth Road', ['16944D0A-DBFF-11EE-A7DF-A8388EA0F4B8', '169628BE-DBFF-11EE-9600-A8388EA0F4B8']], // 1 Kenilworth Road, NG7 1DD; 2 Kenilworth Road, NG7 1DD
        'lenton-avenue' => ['Lenton Avenue', ['2F7E38A8-DBFF-11EE-9EE9-A8388EA0F4B8', '2F819BF6-DBFF-11EE-A6D7-A8388EA0F4B8']], // 1 Lenton Avenue, NG7 1DX; 2 Lenton Avenue, NG7 1DY
        'lenton-court' => ['Lenton Court', ['A45CA6B6-DBFD-11EE-B7E9-A7388EA0F4B8', 'A45E44D0-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Lenton Court, Lenton Avenue, NG7 1DZ; 2 Lenton Court, Lenton Avenue, NG7 1DZ
        'lenton-road' => ['Lenton Road', ['315E829A-DBFF-11EE-841E-A8388EA0F4B8', '31602FFA-DBFF-11EE-A180-A8388EA0F4B8']], // 2 Lenton Road, NG7 1DQ; 3 Lenton Road, NG7 1DP
        'lincoln-circus' => ['Lincoln Circus', ['38471022-DBFF-11EE-8AA7-A8388EA0F4B8', '3848DED4-DBFF-11EE-9155-A8388EA0F4B8']], // 1 Lincoln Circus, NG7 1BG; 2 Lincoln Circus, NG7 1BG
        'maria-court' => ['Maria Court', ['B3D326C4-DBFD-11EE-B7E9-A7388EA0F4B8', 'B3D4F224-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Maria Court, Fishpond Drive, NG7 1DH; 2 Maria Court, Fishpond Drive, NG7 1DH
        'maxtoke-road' => ['Maxtoke Road', ['5756A39C-DBFF-11EE-9E38-A8388EA0F4B8']], // Clumber Lodge, 1 Maxtoke Road, NG7 1EG
        'mevell-court' => ['Mevell Court', ['A14F806A-DBFD-11EE-B7E9-A7388EA0F4B8', 'A15122F8-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Mevell Court, Clumber Road East, NG7 1BD; 2 Mevell Court, Clumber Road East, NG7 1BD
        'newcastle-circus' => ['Newcastle Circus', ['73B2FDE2-DBFF-11EE-AFFF-A8388EA0F4B8', '73B5FA92-DBFF-11EE-846B-A8388EA0F4B8']], // 2 Newcastle Circus, NG7 1BJ; 5 Newcastle Circus, NG7 1BJ
        'newcastle-court' => ['Newcastle Court', ['A5697516-DBFD-11EE-B7E9-A7388EA0F4B8', 'A56B1F92-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Newcastle Court, Newcastle Circus, NG7 1BL; 2 Newcastle Court, Newcastle Circus, NG7 1BL
        'newcastle-drive' => ['Newcastle Drive', ['FB136100-DBFF-11EE-96DB-AC388EA0F4B8', '73BC091E-DBFF-11EE-A2F7-A8388EA0F4B8']], // Flat B, 1 Newcastle Drive, NG7 1AA; Flat A, 1 Newcastle Drive, NG7 1AA
        'north-road' => ['North Road', ['7A7F116A-DBFF-11EE-A7CC-A8388EA0F4B8', '7A80C3AC-DBFF-11EE-9F05-A8388EA0F4B8']], // Flat 1, 1 North Road, NG7 1AG; Flat 2, 1 North Road, NG7 1AG
        'ogle-drive' => ['Ogle Drive', ['D75FA756-DBFE-11EE-8187-AC388EA0F4B8', 'D761A6E6-DBFE-11EE-A5F7-AC388EA0F4B8']], // 1 Ogle Drive, NG7 1ES; 2 Ogle Drive, NG7 1ES
        'park-drive' => ['Park Drive', ['DF087762-DBFE-11EE-9382-AC388EA0F4B8', 'DF0A528A-DBFE-11EE-AE38-AC388EA0F4B8']], // 1 Park Drive, NG7 1DA; 2 Park Drive, NG7 1DA
        'park-terrace' => ['Park Terrace', ['C0CD015E-DBFF-11EE-96DB-AC388EA0F4B8', 'E00D94B2-DBFE-11EE-987C-AC388EA0F4B8']], // 1 Park Terrace, NG1 5DN; 2 Park Terrace, NG1 5DN
        'park-valley' => ['Park Valley', ['E060ADBE-DBFE-11EE-8DC2-AC388EA0F4B8', 'C69FAB4A-DBFF-11EE-96DB-AC388EA0F4B8']], // Flat 2 Arundel House, 1 Park Valley, NG7 1BS; Flat 1 Arundel House, 1 Park Valley, NG7 1BS
        'parkdale-court' => ['Parkdale Court', ['A42BAAE8-DBFD-11EE-B7E9-A7388EA0F4B8', 'A42D41B4-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Parkdale Court, Kenilworth Road, NG7 1DD; 2 Parkdale Court, Kenilworth Road, NG7 1DD
        'pelham-crescent' => ['Pelham Crescent', ['E72185A6-DBFE-11EE-93DE-AC388EA0F4B8', 'E77497DC-DBFE-11EE-95B1-AC388EA0F4B8']], // 1 Pelham Crescent, NG7 1AR; 2 Pelham Crescent, NG7 1AW
        'pelham-mews' => ['Pelham Mews', ['F77D1BFA-DBFD-11EE-B7E9-A7388EA0F4B8', 'F77EBC3A-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Pelham Mews, 19a Pelham Crescent, NG7 1AR; 4 Pelham Mews, 19a Pelham Crescent, NG7 1AR
        'peveril-drive' => ['Peveril Drive', ['EE422318-DBFE-11EE-8874-AC388EA0F4B8', 'EE43DA00-DBFE-11EE-8836-AC388EA0F4B8']], // 1 Peveril Drive, NG7 1DE; 2 Peveril Drive, NG7 1DE
        'peveril-mews' => ['Peveril Mews', ['9DAEEAEA-DBFD-11EE-B7E9-A7388EA0F4B8', '9DB0F470-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Peveril Mews, Alexander Road, NG7 1FH; 2 Peveril Mews, Alexander Road, NG7 1FH
        'potomac-mews' => ['Potomac Mews', ['A160C44C-DBFD-11EE-B7E9-A7388EA0F4B8', 'A16286A6-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Potomac Mews, Clumber Road East, NG7 1LF; 2 Potomac Mews, Clumber Road East, NG7 1LF
        'rock-drive' => ['Rock Drive', ['12331D72-DBFF-11EE-8916-AC388EA0F4B8', '12365938-DBFF-11EE-9B1C-AC388EA0F4B8']], // 1 Rock Drive, NG7 1HW; 2 Rock Drive, NG7 1HW
        'south-road' => ['South Road', ['C6451C52-DBFF-11EE-96DB-AC388EA0F4B8', 'C65BCE48-DBFF-11EE-96DB-AC388EA0F4B8']], // Ground Floor Flat, 1 South Road, NG7 1EB; 2 South Road, NG7 1EB
        'st-helier-court' => ['St Helier Court', ['A58C7E94-DBFD-11EE-B7E9-A7388EA0F4B8', 'A58FF3E4-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 St Helier Court, Park Drive, NG7 1BE; 2 St Helier Court, Park Drive, NG7 1BE
        'tattershall-drive' => ['Tattershall Drive', ['09D24D50-DC00-11EE-96DB-AC388EA0F4B8', '09D3D81E-DC00-11EE-96DB-AC388EA0F4B8']], // Flat 1, 1 Tattershall Drive, NG7 1BX; Flat 2, 1 Tattershall Drive, NG7 1BX
        'tennis-drive' => ['Tennis Drive', ['63C05C86-DBFF-11EE-B1BD-AC388EA0F4B8', '63C21C06-DBFF-11EE-99B8-AC388EA0F4B8']], // 1 Tennis Drive, NG7 1AE; 3 Tennis Drive, NG7 1AE
        'tennis-mews' => ['Tennis Mews', ['C514EC06-DBFD-11EE-B7E9-A7388EA0F4B8', 'C5168CB4-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Tennis Mews, Tennis Drive, NG7 1EX; 2 Tennis Mews, Tennis Drive, NG7 1EX
        'western-terrace' => ['Western Terrace', ['96A7BAD6-DBFF-11EE-96DB-AC388EA0F4B8', '96A94950-DBFF-11EE-96DB-AC388EA0F4B8']], // Flat 1, 1 Western Terrace, NG7 1AF; Flat 2, 1 Western Terrace, NG7 1AF
        'yeomans-court' => ['Yeomans Court', ['A17150C8-DBFD-11EE-B7E9-A7388EA0F4B8', 'A17304AE-DBFD-11EE-B7E9-A7388EA0F4B8']], // 1 Yeomans Court, Clumber Road West, NG7 1EU; 2 Yeomans Court, Clumber Road West, NG7 1EU
    ];

    /** @return array<string, string> slug => name, in alphabetical order */
    public static function names(): array
    {
        return array_map(static fn(array $s) => $s[0], self::STREETS);
    }

    public static function exists(string $slug): bool
    {
        return isset(self::STREETS[$slug]);
    }

    public static function name(string $slug): string
    {
        return self::STREETS[$slug][0] ?? $slug;
    }

    /** @return list<string> */
    public static function samplePlaces(string $slug): array
    {
        return self::STREETS[$slug][1] ?? [];
    }
}
