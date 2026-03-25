import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "%NATIVE_APPLICATION_ID%"
    compileSdk = 35

    defaultConfig {
        applicationId = "%NATIVE_APPLICATION_ID%"
        minSdk = 28
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    implementation("dev.hotwire:core:1.2.6")
    implementation("dev.hotwire:navigation-fragments:1.2.6")
    implementation("com.google.android.material:material:1.12.0")
}
