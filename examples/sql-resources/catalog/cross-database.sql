-- Replace registered logical IDs as appropriate before activation. Physical database names are never authored here.
SELECT C.CustomerId, C.CustomerName, O.OrderId, O.Amount
FROM {{database:crm}}.dbo.DemoCustomers AS C
INNER JOIN {{database:sales}}.dbo.DemoOrders AS O ON O.CustomerId = C.CustomerId
