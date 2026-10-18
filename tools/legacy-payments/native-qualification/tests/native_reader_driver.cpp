#define PAYMENTER_QA_READER_NO_MAIN
#include "../native/committed_reader.cpp"
#include <iostream>
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    if(argc!=3)return 2;
    std::string mode=argv[1],root=argv[2];mysql_test_mode=mode;
    mysql_test_credential=root+"/credential";
    try {
        auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
        perform_native_read(dir,mysql_test_credential+(mode=="credential-symlink"?"-link":""),Owner{getuid(),getgid()},"qa","/fictional.sock",
            []{return "11111111-1111-1111-1111-111111111111";});
        if(mode!="channel" && mode!="observation" && mode!="spaced-config")return 3;
    } catch(const ProbeError&) {if(mode=="channel" || mode=="observation" || mode=="spaced-config")return 4;}
    catch(const ReaderError&) {if(mode=="channel" || mode=="observation" || mode=="spaced-config")return 5;}
    catch(const std::exception& e) {std::cerr<<e.what();return 6;}
}
