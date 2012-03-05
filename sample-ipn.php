<?php
if (isset($_GET['payvmentOrder']) && isset($_GET['payvmentId'])) {
  echo "GOT ping from IPN!!!<BR>";
  echo "PayvmentOrder: ".$_GET['payvmentOrder']."<BR>";
  echo "payvmentId: ".$_GET['payvmentId']."<BR>";
}
echo "<BR>HELLO WORLD!<BR>";
?>