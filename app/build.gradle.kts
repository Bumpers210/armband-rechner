import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

val betaVersionCode = 5
val betaVersionName = "1.1.0-beta.4"
val releaseSigningPropertiesFile = rootProject.file(".signing/keystore.properties")
val releaseSigningProperties = Properties().apply {
    if (releaseSigningPropertiesFile.isFile) {
        releaseSigningPropertiesFile.inputStream().use(::load)
    }
}
val betaSigningPropertiesFile = rootProject.file(".signing/beta-keystore.properties")
val betaSigningProperties = Properties().apply {
    if (betaSigningPropertiesFile.isFile) {
        betaSigningPropertiesFile.inputStream().use(::load)
    }
}
val betaSigningStoreFile = System.getenv("CARMAJA_BETA_KEYSTORE_PATH")
    ?.takeIf(String::isNotBlank)
    ?.let(rootProject::file)
    ?: betaSigningProperties.getProperty("storeFile")
        ?.takeIf(String::isNotBlank)
        ?.let { rootProject.file(".signing/$it") }
val betaSigningStorePassword = System.getenv("CARMAJA_BETA_STORE_PASSWORD")
    ?.takeIf(String::isNotBlank)
    ?: betaSigningProperties.getProperty("storePassword")?.takeIf(String::isNotBlank)
val betaSigningKeyPassword = System.getenv("CARMAJA_BETA_KEY_PASSWORD")
    ?.takeIf(String::isNotBlank)
    ?: betaSigningProperties.getProperty("keyPassword")?.takeIf(String::isNotBlank)
val betaSigningKeyAlias = "carmaja-product-management-beta"
val betaSigningReady = betaSigningStoreFile?.isFile == true &&
    betaSigningStorePassword != null &&
    betaSigningKeyPassword != null
val betaBuildRequested = gradle.startParameter.taskNames.any { taskName ->
    taskName.substringAfterLast(':').contains("Beta", ignoreCase = true)
}

if (betaBuildRequested && !betaSigningReady) {
    throw GradleException(
        "Die stabile Beta-Signierung fehlt. Debug-Signierung ist fuer Beta-Builds nicht erlaubt.",
    )
}

android {
    namespace = "de.carmajaperlen.armbandrechner"
    compileSdk = 36

    defaultConfig {
        applicationId = "de.carmajaperlen.armbandrechner"
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        manifestPlaceholders["appLabel"] = "Armband-Rechner"
        buildConfigField("String", "DEFAULT_PRODUCT_API_BASE_URL", "\"\"")
        buildConfigField("String", "PRODUCT_PUBLISH_TARGET", "\"\"")
    }

    signingConfigs {
        if (releaseSigningPropertiesFile.isFile) {
            create("release") {
                storeFile = rootProject.file(".signing/${releaseSigningProperties["storeFile"]}")
                storePassword = releaseSigningProperties["storePassword"] as String
                keyAlias = releaseSigningProperties["keyAlias"] as String
                keyPassword = releaseSigningProperties["keyPassword"] as String
            }
        }
        if (betaSigningReady) {
            create("beta") {
                storeFile = betaSigningStoreFile
                storePassword = betaSigningStorePassword
                keyAlias = betaSigningKeyAlias
                keyPassword = betaSigningKeyPassword
            }
        }
    }

    buildTypes {
        debug {
            manifestPlaceholders["appLabel"] = "Armband-Rechner"
        }

        create("beta") {
            initWith(getByName("debug"))
            applicationIdSuffix = ".test"
            isDebuggable = true
            signingConfig = if (betaSigningReady) {
                signingConfigs.getByName("beta")
            } else {
                null
            }
            matchingFallbacks += listOf("debug")
            manifestPlaceholders["appLabel"] = "Carmaja-Perlen Produktverwaltung Test"
            buildConfigField(
                "String",
                "DEFAULT_PRODUCT_API_BASE_URL",
                "\"https://test-api.carmaja-perlen.de/\"",
            )
            buildConfigField("String", "PRODUCT_PUBLISH_TARGET", "\"test\"")
        }

        release {
            isMinifyEnabled = false
            if (releaseSigningPropertiesFile.isFile) {
                signingConfig = signingConfigs.getByName("release")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    buildFeatures {
        buildConfig = true
        compose = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlin {
        compilerOptions {
            jvmTarget.set(JvmTarget.JVM_17)
        }
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

androidComponents {
    onVariants(selector().withBuildType("beta")) { variant ->
        variant.outputs.forEach { output ->
            output.versionCode.set(betaVersionCode)
            output.versionName.set(betaVersionName)
        }
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.06.00")

    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material:material-icons-core")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.datastore:datastore-preferences:1.2.1")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-ktx:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")

    testImplementation("junit:junit:4.13.2")
    testImplementation("androidx.datastore:datastore-preferences-core:1.2.1")
    testImplementation("org.json:json:20250517")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")

    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.7.0")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
