SELECT
    Item_Code,
    Item_Desc,
    Sale_Rate,
    Item_MRP,
    Std_Vat,
    cl_stock,
    round (Cl_Stock*Sale_Rate,2) as stock_value
FROM ItemMasterTable