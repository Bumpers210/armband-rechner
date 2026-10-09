import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.Signature;
import java.util.Arrays;
import java.util.HexFormat;

class VerifyCartBetaKeystore {
    public static void main(String[] args) {
        char[] storePassword = null;
        char[] keyPassword = null;
        try {
            Path path = Path.of(System.getenv("CARMAJA_BETA_KEYSTORE_PATH"));
            Path expected = Path.of("G:\\BS-Stein-Hart-shop-cart\\.signing\\carmaja-product-management-beta.jks");
            if (Files.isSymbolicLink(path) || !path.toRealPath().equals(expected.toRealPath())) {
                throw new IllegalArgumentException();
            }
            storePassword = System.getenv("CARMAJA_BETA_STORE_PASSWORD").toCharArray();
            keyPassword = System.getenv("CARMAJA_BETA_KEY_PASSWORD").toCharArray();
            KeyStore store = KeyStore.getInstance(path.toFile(), storePassword);
            String alias = "carmaja-product-management-beta";
            if (!store.isKeyEntry(alias)) { throw new IllegalArgumentException(); }
            String fingerprint = HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256")
                .digest(store.getCertificate(alias).getEncoded()));
            if (!fingerprint.equals("235ee4d746fdf5490df3c292f882a76429795e99feaeb58dea37443e944d5d2a")) {
                throw new IllegalArgumentException();
            }
            PrivateKey key = (PrivateKey) store.getKey(alias, keyPassword);
            String algorithm = switch (key.getAlgorithm()) {
                case "RSA" -> "SHA256withRSA";
                case "EC" -> "SHA256withECDSA";
                default -> throw new IllegalArgumentException();
            };
            byte[] challenge = new byte[32];
            new java.security.SecureRandom().nextBytes(challenge);
            Signature signer = Signature.getInstance(algorithm);
            signer.initSign(key); signer.update(challenge);
            byte[] signed = signer.sign();
            signer.initVerify(store.getCertificate(alias)); signer.update(challenge);
            if (!signer.verify(signed)) { throw new IllegalArgumentException(); }
            System.out.println("CART_BETA_SIGNING_VERIFIED");
        } catch (Throwable ignored) {
            System.out.println("CART_BETA_SIGNING_INVALID");
            System.exit(1);
        } finally {
            if (storePassword != null) { Arrays.fill(storePassword, '\0'); }
            if (keyPassword != null) { Arrays.fill(keyPassword, '\0'); }
        }
    }
}
